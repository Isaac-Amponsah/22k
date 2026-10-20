-- =============================================================================
-- Migration: allowance and deduction types, SSNIT tiers, Tier 3, personal tax reliefs
-- Target:    PostgreSQL
-- Date:      2026-10-20
-- Ref:       App\Services\PayrollCalculator, App\Services\PayItemService, App\Services\TaxReliefTypes
--
-- Pay is no longer basic salary plus one flat allowance:
--   - Each Client keeps its own list of pay items: allowances (motivational, management, education, …),
--     each taxable or not, and after-tax deductions (loan, welfare, union dues, …). An employee holds
--     an amount on any of them.
--   - The statutory 18.5% of basic salary (employee + employer SSNIT) is split into Tier 1 (paid to
--     SSNIT) and Tier 2 (paid to the occupational trustee). The Tier 1 share is a rate, effective-dated
--     with the rest; Tier 2 is what is left, so the two always add up to the contributions taken.
--   - An employee may pay a voluntary Tier 3 percentage of basic salary. It is taken from pay, and
--     relieved from tax up to a capped percentage of basic salary (also a rate).
--   - Personal reliefs (marriage/responsibility, child education, …) are claimed per employee. Their
--     annual amounts are law, effective-dated with the rates, and taken monthly off chargeable income.
--
-- Existing salaries and runs keep exactly the pay they had: each Client with a flat allowance gets one
-- taxable "Allowance" item holding it, and past run lines are backfilled with the new figures they imply
-- (all allowance taxable, no Tier 3, no reliefs, no deductions).
--
-- employee_salaries, payroll_runs and payroll_run_lines force row-level security on their owner and a
-- migration has no Client in scope, so FORCE is lifted while existing rows are read and backfilled and
-- put back in the same transaction (the runner wraps each file in one). The new tables are filled
-- before their own policies are switched on.
--
-- Idempotent: safe to re-run.
-- =============================================================================

-- -----------------------------------------------------------------------------
-- Statutory rates: Tier 1 share, Tier 3 relief cap, personal relief amounts
-- -----------------------------------------------------------------------------
ALTER TABLE payroll_statutory_rates ADD COLUMN IF NOT EXISTS ssnit_tier1_percent      NUMERIC(5,2);
ALTER TABLE payroll_statutory_rates ADD COLUMN IF NOT EXISTS tier3_relief_cap_percent NUMERIC(5,2);

-- Act 766: of the 18.5%, 13.5% goes to SSNIT (Tier 1) and 5% to the Tier 2 trustee. Voluntary Tier 3
-- contributions are tax-relieved up to 16.5% of basic salary.
UPDATE payroll_statutory_rates SET ssnit_tier1_percent = 13.50 WHERE ssnit_tier1_percent IS NULL;
UPDATE payroll_statutory_rates SET tier3_relief_cap_percent = 16.50 WHERE tier3_relief_cap_percent IS NULL;

ALTER TABLE payroll_statutory_rates ALTER COLUMN ssnit_tier1_percent SET NOT NULL;
ALTER TABLE payroll_statutory_rates ALTER COLUMN tier3_relief_cap_percent SET NOT NULL;

DO $$
BEGIN
	IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_payroll_statutory_rates_tiers') THEN
		ALTER TABLE payroll_statutory_rates ADD CONSTRAINT chk_payroll_statutory_rates_tiers CHECK (
			ssnit_tier1_percent BETWEEN 0 AND employee_ssnit_percent + employer_ssnit_percent
			AND tier3_relief_cap_percent BETWEEN 0 AND 100
		);
	END IF;
END
$$;

-- One row per relief a rate set allows. A relief is either a fixed annual amount per claim (a child, a
-- dependant) up to max_claims, or a percentage of assessable income (disability). Codes are listed in
-- App\Services\TaxReliefTypes.
CREATE TABLE IF NOT EXISTS payroll_relief_rates (
	statutory_rate_id     INTEGER       NOT NULL REFERENCES payroll_statutory_rates (statutory_rate_id) ON DELETE CASCADE,
	relief_code           VARCHAR(30)   NOT NULL,
	annual_amount         NUMERIC(12,2),
	percent_of_assessable NUMERIC(5,2),
	max_claims            INTEGER       NOT NULL DEFAULT 1,
	PRIMARY KEY (statutory_rate_id, relief_code),
	CONSTRAINT chk_payroll_relief_rates_values CHECK (
		(annual_amount IS NULL) <> (percent_of_assessable IS NULL)
		AND (annual_amount IS NULL OR annual_amount >= 0)
		AND (percent_of_assessable IS NULL OR percent_of_assessable BETWEEN 0 AND 100)
		AND max_claims >= 1
	)
);

-- GRA personal reliefs (annual), given to every rate set that has none yet.
INSERT INTO payroll_relief_rates (statutory_rate_id, relief_code, annual_amount, percent_of_assessable, max_claims)
SELECT rate_set.statutory_rate_id, relief.relief_code, relief.annual_amount, relief.percent_of_assessable, relief.max_claims
FROM payroll_statutory_rates rate_set
CROSS JOIN (VALUES
	('marriage_responsibility', 1200.00, NULL::NUMERIC, 1),
	('child_education',          600.00, NULL::NUMERIC, 3),
	('aged_dependant',          1500.00, NULL::NUMERIC, 2),
	('old_age',                 1500.00, NULL::NUMERIC, 1),
	('professional_training',   2000.00, NULL::NUMERIC, 1),
	('disability',                 NULL, 25.00,         1)
) AS relief(relief_code, annual_amount, percent_of_assessable, max_claims)
WHERE NOT EXISTS (SELECT 1 FROM payroll_relief_rates existing WHERE existing.statutory_rate_id = rate_set.statutory_rate_id)
ON CONFLICT (statutory_rate_id, relief_code) DO NOTHING;

-- -----------------------------------------------------------------------------
-- A Client's pay items: allowance and deduction types
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS client_pay_items (
	pay_item_id   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	client_id     INTEGER      NOT NULL REFERENCES clients (client_id) ON DELETE RESTRICT,
	-- 'allowance': paid on top of basic salary. 'deduction': taken from pay after PAYE.
	item_kind     VARCHAR(10)  NOT NULL,
	item_name     VARCHAR(100) NOT NULL,
	-- Allowances only: a taxable allowance is part of chargeable income; a non-taxable one is paid as is.
	is_taxable    BOOLEAN      NOT NULL DEFAULT TRUE,
	display_order INTEGER      NOT NULL DEFAULT 0,
	created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	CONSTRAINT uq_client_pay_items_client_item UNIQUE (client_id, pay_item_id),
	CONSTRAINT chk_client_pay_items_kind CHECK (item_kind IN ('allowance', 'deduction'))
);

CREATE INDEX IF NOT EXISTS idx_client_pay_items_client ON client_pay_items (client_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_client_pay_items_name ON client_pay_items (client_id, item_kind, LOWER(item_name));

-- What one employee gets (or has taken) on one pay item each month. No row = nothing.
CREATE TABLE IF NOT EXISTS employee_pay_item_amounts (
	client_id   INTEGER       NOT NULL,
	employee_id INTEGER       NOT NULL,
	pay_item_id INTEGER       NOT NULL,
	amount      NUMERIC(12,2) NOT NULL,
	updated_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (employee_id, pay_item_id),
	CONSTRAINT fk_employee_pay_item_amounts_employee
		FOREIGN KEY (client_id, employee_id) REFERENCES employees (client_id, employee_id) ON DELETE CASCADE,
	-- A pay item someone still holds an amount on cannot be removed under them.
	CONSTRAINT fk_employee_pay_item_amounts_item
		FOREIGN KEY (client_id, pay_item_id) REFERENCES client_pay_items (client_id, pay_item_id) ON DELETE RESTRICT,
	CONSTRAINT chk_employee_pay_item_amounts_amount CHECK (amount > 0)
);

CREATE INDEX IF NOT EXISTS idx_employee_pay_item_amounts_client ON employee_pay_item_amounts (client_id);

-- -----------------------------------------------------------------------------
-- Voluntary Tier 3 and personal relief claims, per employee
-- -----------------------------------------------------------------------------
ALTER TABLE employee_salaries ADD COLUMN IF NOT EXISTS tier3_percent NUMERIC(5,2) NOT NULL DEFAULT 0;

DO $$
BEGIN
	IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_employee_salaries_tier3') THEN
		ALTER TABLE employee_salaries ADD CONSTRAINT chk_employee_salaries_tier3 CHECK (tier3_percent BETWEEN 0 AND 100);
	END IF;
END
$$;

-- claim_count: children or dependants claimed for; 1 for a relief that is simply claimed or not.
CREATE TABLE IF NOT EXISTS employee_tax_reliefs (
	client_id   INTEGER     NOT NULL,
	employee_id INTEGER     NOT NULL,
	relief_code VARCHAR(30) NOT NULL,
	claim_count INTEGER     NOT NULL DEFAULT 1,
	updated_at  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
	PRIMARY KEY (employee_id, relief_code),
	CONSTRAINT fk_employee_tax_reliefs_employee
		FOREIGN KEY (client_id, employee_id) REFERENCES employees (client_id, employee_id) ON DELETE CASCADE,
	CONSTRAINT chk_employee_tax_reliefs_count CHECK (claim_count >= 1)
);

CREATE INDEX IF NOT EXISTS idx_employee_tax_reliefs_client ON employee_tax_reliefs (client_id);

-- -----------------------------------------------------------------------------
-- Runs and lines: the new figures, and each line's items as they were named that month
-- -----------------------------------------------------------------------------
ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS ssnit_tier1_percent      NUMERIC(5,2);
ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS total_taxable_allowance  NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS total_tier3_contribution NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS total_tier3_relief       NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS total_personal_relief    NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS total_other_deductions   NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS total_ssnit_tier1        NUMERIC(14,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_runs ADD COLUMN IF NOT EXISTS total_ssnit_tier2        NUMERIC(14,2) NOT NULL DEFAULT 0;

ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS ssnit_number              VARCHAR(30);
ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS tax_identification_number VARCHAR(30);
ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS taxable_allowance         NUMERIC(12,2);
ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS tier3_contribution        NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS tier3_relief              NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS personal_relief           NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS other_deductions          NUMERIC(12,2) NOT NULL DEFAULT 0;
ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS ssnit_tier1               NUMERIC(12,2);
ALTER TABLE payroll_run_lines ADD COLUMN IF NOT EXISTS ssnit_tier2               NUMERIC(12,2);

DO $$
BEGIN
	IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'uq_payroll_run_lines_client_line') THEN
		ALTER TABLE payroll_run_lines ADD CONSTRAINT uq_payroll_run_lines_client_line UNIQUE (client_id, payroll_run_line_id);
	END IF;
END
$$;

-- Allowances, deductions and reliefs on one line, as named and taxed that month.
CREATE TABLE IF NOT EXISTS payroll_run_line_items (
	payroll_run_line_item_id INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
	client_id                INTEGER       NOT NULL,
	payroll_run_line_id      INTEGER       NOT NULL,
	item_kind                VARCHAR(10)   NOT NULL,
	item_name                VARCHAR(100)  NOT NULL,
	-- Allowances only.
	is_taxable               BOOLEAN,
	amount                   NUMERIC(12,2) NOT NULL,
	item_order               INTEGER       NOT NULL DEFAULT 0,
	CONSTRAINT fk_payroll_run_line_items_line
		FOREIGN KEY (client_id, payroll_run_line_id) REFERENCES payroll_run_lines (client_id, payroll_run_line_id) ON DELETE CASCADE,
	CONSTRAINT chk_payroll_run_line_items_kind CHECK (item_kind IN ('allowance', 'deduction', 'relief'))
);

CREATE INDEX IF NOT EXISTS idx_payroll_run_line_items_line ON payroll_run_line_items (payroll_run_line_id);
CREATE INDEX IF NOT EXISTS idx_payroll_run_line_items_client ON payroll_run_line_items (client_id);

-- -----------------------------------------------------------------------------
-- Carry existing pay over
-- -----------------------------------------------------------------------------
DO $$
BEGIN
	IF EXISTS (
		SELECT 1 FROM information_schema.columns
		WHERE table_schema = 'public' AND table_name = 'employee_salaries' AND column_name = 'flat_allowance'
	) THEN
		ALTER TABLE employee_salaries NO FORCE ROW LEVEL SECURITY;

		INSERT INTO client_pay_items (client_id, item_kind, item_name, is_taxable, display_order)
		SELECT DISTINCT salary.client_id, 'allowance', 'Allowance', TRUE, 1
		FROM employee_salaries salary
		WHERE salary.flat_allowance > 0
		ON CONFLICT DO NOTHING;

		INSERT INTO employee_pay_item_amounts (client_id, employee_id, pay_item_id, amount)
		SELECT salary.client_id, salary.employee_id, pay_item.pay_item_id, salary.flat_allowance
		FROM employee_salaries salary
		JOIN client_pay_items pay_item
		  ON pay_item.client_id = salary.client_id AND pay_item.item_kind = 'allowance' AND pay_item.item_name = 'Allowance'
		WHERE salary.flat_allowance > 0
		ON CONFLICT (employee_id, pay_item_id) DO NOTHING;

		ALTER TABLE employee_salaries DROP CONSTRAINT IF EXISTS chk_employee_salaries_amounts;
		ALTER TABLE employee_salaries DROP COLUMN flat_allowance;
		ALTER TABLE employee_salaries ADD CONSTRAINT chk_employee_salaries_amounts CHECK (basic_salary >= 0);

		ALTER TABLE employee_salaries FORCE ROW LEVEL SECURITY;
	END IF;
END
$$;

-- Unconditional: with FORCE on, this connection could not see whether any row still needs it. Each
-- statement touches only rows not yet backfilled.
DO $$
BEGIN
	ALTER TABLE payroll_runs NO FORCE ROW LEVEL SECURITY;
	ALTER TABLE payroll_run_lines NO FORCE ROW LEVEL SECURITY;
	ALTER TABLE payroll_run_line_items NO FORCE ROW LEVEL SECURITY;

	UPDATE payroll_runs run
	SET ssnit_tier1_percent = rate_set.ssnit_tier1_percent
	FROM payroll_statutory_rates rate_set
	WHERE rate_set.statutory_rate_id = run.statutory_rate_id AND run.ssnit_tier1_percent IS NULL;

	-- Tier 1 rounded to the pesewa as PayrollCalculator does; Tier 2 is the rest of what was taken.
	UPDATE payroll_run_lines line
	SET taxable_allowance = line.allowance,
	    ssnit_tier1       = ROUND(line.basic_salary * run.ssnit_tier1_percent / 100, 2),
	    ssnit_tier2       = line.employee_ssnit + line.employer_ssnit - ROUND(line.basic_salary * run.ssnit_tier1_percent / 100, 2)
	FROM payroll_runs run
	WHERE run.payroll_run_id = line.payroll_run_id AND line.ssnit_tier1 IS NULL;

	INSERT INTO payroll_run_line_items (client_id, payroll_run_line_id, item_kind, item_name, is_taxable, amount, item_order)
	SELECT line.client_id, line.payroll_run_line_id, 'allowance', 'Allowance', TRUE, line.allowance, 1
	FROM payroll_run_lines line
	WHERE line.allowance > 0
	  AND NOT EXISTS (SELECT 1 FROM payroll_run_line_items item WHERE item.payroll_run_line_id = line.payroll_run_line_id);

	UPDATE payroll_runs run
	SET total_taxable_allowance = line_totals.total_taxable_allowance,
	    total_ssnit_tier1       = line_totals.total_ssnit_tier1,
	    total_ssnit_tier2       = line_totals.total_ssnit_tier2
	FROM (
		SELECT payroll_run_id,
		       SUM(taxable_allowance) AS total_taxable_allowance,
		       SUM(ssnit_tier1)       AS total_ssnit_tier1,
		       SUM(ssnit_tier2)       AS total_ssnit_tier2
		FROM payroll_run_lines
		GROUP BY payroll_run_id
	) line_totals
	WHERE line_totals.payroll_run_id = run.payroll_run_id;

	ALTER TABLE payroll_runs FORCE ROW LEVEL SECURITY;
	ALTER TABLE payroll_run_lines FORCE ROW LEVEL SECURITY;
	ALTER TABLE payroll_run_line_items FORCE ROW LEVEL SECURITY;
END
$$;

ALTER TABLE payroll_runs ALTER COLUMN ssnit_tier1_percent SET NOT NULL;
ALTER TABLE payroll_run_lines ALTER COLUMN taxable_allowance SET NOT NULL;
ALTER TABLE payroll_run_lines ALTER COLUMN ssnit_tier1 SET NOT NULL;
ALTER TABLE payroll_run_lines ALTER COLUMN ssnit_tier2 SET NOT NULL;

-- -----------------------------------------------------------------------------
-- Isolation for the new Client tables (as 2026_10_05_02_client_isolation.sql)
-- -----------------------------------------------------------------------------
DO $$
DECLARE
	client_table TEXT;
BEGIN
	FOREACH client_table IN ARRAY ARRAY['client_pay_items', 'employee_pay_item_amounts', 'employee_tax_reliefs', 'payroll_run_line_items']
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

GRANT SELECT, INSERT, UPDATE, DELETE ON client_pay_items          TO {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON employee_pay_item_amounts TO {{app_role}};
GRANT SELECT, INSERT, UPDATE, DELETE ON employee_tax_reliefs      TO {{app_role}};
GRANT SELECT, INSERT, DELETE         ON payroll_run_line_items    TO {{app_role}};
GRANT SELECT, INSERT, DELETE         ON payroll_relief_rates      TO {{app_role}};
