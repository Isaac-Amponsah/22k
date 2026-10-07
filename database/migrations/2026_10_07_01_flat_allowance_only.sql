-- =============================================================================
-- Migration: an allowance is always a flat amount
-- Target:    PostgreSQL
-- Date:      2026-10-07
-- Ref:       App\Services\PayrollCalculator, App\Models\EmployeeSalary
--
-- The 'target' allowance type (top chargeable income up to a set figure) is gone. Each salary on it
-- becomes the flat allowance it pays today, worked out exactly as PayrollCalculator does (SSNIT
-- rounded to the pesewa) at the rate set in force now, so no one's pay moves.
--
-- employee_salaries forces row-level security on its owner, and a migration has no Client in scope,
-- so the conversion would see no rows. FORCE is lifted for the update and put back in the same
-- transaction (the runner wraps each file in one).
--
-- Idempotent: safe to re-run.
-- =============================================================================

DO $$
BEGIN
	IF EXISTS (
		SELECT 1 FROM information_schema.columns
		WHERE table_schema = 'public' AND table_name = 'employee_salaries' AND column_name = 'allowance_mode'
	) THEN
		ALTER TABLE employee_salaries NO FORCE ROW LEVEL SECURITY;

		UPDATE employee_salaries salary
		SET flat_allowance = GREATEST(
			    salary.target_chargeable_income
			    - (salary.basic_salary - ROUND(salary.basic_salary * rate_in_force.employee_ssnit_percent) / 100),
			    0
			),
			updated_at = CURRENT_TIMESTAMP
		FROM (
			SELECT employee_ssnit_percent
			FROM payroll_statutory_rates
			-- As PayrollService::rateSetInForce() picks it for this month: in force by the month's last day.
			WHERE effective_from <= (DATE_TRUNC('month', CURRENT_DATE) + INTERVAL '1 month - 1 day')::DATE
			ORDER BY effective_from DESC
			LIMIT 1
		) rate_in_force
		WHERE salary.allowance_mode = 'target';

		ALTER TABLE employee_salaries FORCE ROW LEVEL SECURITY;
	END IF;
END
$$;

ALTER TABLE employee_salaries DROP CONSTRAINT IF EXISTS chk_employee_salaries_mode;
ALTER TABLE employee_salaries DROP CONSTRAINT IF EXISTS chk_employee_salaries_amounts;
ALTER TABLE employee_salaries DROP COLUMN IF EXISTS allowance_mode;
ALTER TABLE employee_salaries DROP COLUMN IF EXISTS target_chargeable_income;

DO $$
BEGIN
	IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'chk_employee_salaries_amounts') THEN
		ALTER TABLE employee_salaries ADD CONSTRAINT chk_employee_salaries_amounts
			CHECK (basic_salary >= 0 AND flat_allowance >= 0);
	END IF;
END
$$;

-- A run line's allowance is always the flat amount, so the type it was worked out by says nothing.
ALTER TABLE payroll_run_lines DROP COLUMN IF EXISTS allowance_mode;
