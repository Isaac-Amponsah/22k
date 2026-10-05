-- =============================================================================
-- Migration: sending a payroll run to the Client's bank by email
-- Target:    PostgreSQL
-- Date:      2026-10-05
-- Ref:       App\Models\ClientBankEmailSetting, App\Models\PayrollRunBankEmail,
--            App\Services\BankPayrollEmailService
--
--   client_bank_email_settings — one row per Client: the bank, who at the bank receives the
--                                payroll, and the email template (subject and body with
--                                {placeholders}).
--   payroll_run_bank_emails    — a record of every payroll email sent: to whom, with what words,
--                                by which accountant. Never changed or deleted.
--
-- Both carry client_id and are confined by the same row-level security policy as every other
-- Client table (see 2026_10_05_02_client_isolation.sql).
--
-- Idempotent: safe to re-run.
-- =============================================================================

CREATE TABLE IF NOT EXISTS client_bank_email_settings (
	client_id              INTEGER      PRIMARY KEY REFERENCES clients (client_id) ON DELETE RESTRICT,
	bank_name              VARCHAR(150),
	-- JSON array of email addresses, in the order they were entered.
	recipient_emails       JSONB        NOT NULL DEFAULT '[]'::jsonb,
	email_subject_template VARCHAR(200) NOT NULL,
	email_body_template    TEXT         NOT NULL,
	updated_by             INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	created_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at             TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT chk_client_bank_email_settings_recipients CHECK (jsonb_typeof(recipient_emails) = 'array')
);

CREATE TABLE IF NOT EXISTS payroll_run_bank_emails (
	payroll_run_bank_email_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	client_id                 INTEGER      NOT NULL,
	payroll_run_id            INTEGER      NOT NULL,
	-- What was sent, as it was sent: the settings may change afterwards.
	recipient_emails          JSONB        NOT NULL,
	email_subject             VARCHAR(200) NOT NULL,
	email_body                TEXT         NOT NULL,
	attachment_filename       VARCHAR(100) NOT NULL,
	sent_by                   INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	sent_at                   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT fk_payroll_run_bank_emails_run
		FOREIGN KEY (client_id, payroll_run_id) REFERENCES payroll_runs (client_id, payroll_run_id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_payroll_run_bank_emails_run ON payroll_run_bank_emails (client_id, payroll_run_id);

-- -----------------------------------------------------------------------------
-- Only the Client in scope
-- -----------------------------------------------------------------------------
DO $$
DECLARE
	client_table TEXT;
BEGIN
	FOREACH client_table IN ARRAY ARRAY['client_bank_email_settings', 'payroll_run_bank_emails']
	LOOP
		EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', client_table);
		EXECUTE format('ALTER TABLE %I FORCE ROW LEVEL SECURITY', client_table);
		EXECUTE format('DROP POLICY IF EXISTS rows_of_client_in_scope ON %I', client_table);
		EXECUTE format(
			'CREATE POLICY rows_of_client_in_scope ON %I
				USING (client_id = app_current_client_id() AND client_id IN (SELECT client_id FROM clients))
				WITH CHECK (client_id = app_current_client_id() AND client_id IN (SELECT client_id FROM clients))',
			client_table
		);
	END LOOP;
END
$$;

-- A sent email is a record: it can be added and read, never changed or removed.
GRANT SELECT, INSERT, UPDATE ON client_bank_email_settings TO {{app_role}};
GRANT SELECT, INSERT         ON payroll_run_bank_emails    TO {{app_role}};
