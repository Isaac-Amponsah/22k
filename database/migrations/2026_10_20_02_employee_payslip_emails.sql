-- =============================================================================
-- Migration: emailing each employee their payslip
-- Target:    PostgreSQL
-- Date:      2026-10-20
-- Ref:       App\Services\PayslipEmailService, App\Models\PayrollRunPayslipEmail
--
-- When a payroll run is sent to the Client's bank, every employee it pays who has an email address
-- receives their payslip as a PDF. Each payslip emailed is recorded against its run line, once: sending
-- the run to the bank again, or retrying after a failure, emails only the employees not yet sent theirs.
--
--   employees.email_address    — where an employee's payslips go.
--   payroll_run_payslip_emails — one row per payslip emailed: to which address, when, by whom. Never
--                                changed or deleted.
--
-- payroll_run_payslip_emails carries client_id and is confined by the same row-level security policy as
-- every other Client table (see 2026_10_05_02_client_isolation.sql). employees already has its policy and
-- grants; the new column falls under both.
--
-- Idempotent: safe to re-run.
-- =============================================================================

ALTER TABLE employees ADD COLUMN IF NOT EXISTS email_address VARCHAR(190);

CREATE TABLE IF NOT EXISTS payroll_run_payslip_emails (
	payroll_run_payslip_email_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	client_id                    INTEGER      NOT NULL,
	payroll_run_id               INTEGER      NOT NULL,
	payroll_run_line_id          INTEGER      NOT NULL,
	-- The address as it was sent to: the employee's may change afterwards.
	recipient_email              VARCHAR(190) NOT NULL,
	sent_by                      INTEGER REFERENCES users (user_id) ON DELETE SET NULL,
	sent_at                      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT uq_payroll_run_payslip_emails_line UNIQUE (payroll_run_line_id),
	CONSTRAINT fk_payroll_run_payslip_emails_run
		FOREIGN KEY (client_id, payroll_run_id) REFERENCES payroll_runs (client_id, payroll_run_id) ON DELETE RESTRICT,
	-- Only finalised or paid runs are emailed, and their lines are never rebuilt or removed.
	CONSTRAINT fk_payroll_run_payslip_emails_line
		FOREIGN KEY (client_id, payroll_run_line_id) REFERENCES payroll_run_lines (client_id, payroll_run_line_id) ON DELETE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_payroll_run_payslip_emails_run ON payroll_run_payslip_emails (client_id, payroll_run_id);

ALTER TABLE payroll_run_payslip_emails ENABLE ROW LEVEL SECURITY;
ALTER TABLE payroll_run_payslip_emails FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS rows_of_client_in_scope ON payroll_run_payslip_emails;
CREATE POLICY rows_of_client_in_scope ON payroll_run_payslip_emails
	USING (client_id = app_current_client_id() AND client_id IN (SELECT client_id FROM clients))
	WITH CHECK (client_id = app_current_client_id() AND client_id IN (SELECT client_id FROM clients));

-- A sent payslip is a record: it can be added and read, never changed or removed.
GRANT SELECT, INSERT ON payroll_run_payslip_emails TO {{app_role}};
