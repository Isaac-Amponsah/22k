-- =============================================================================
-- Migration: indexes for the lookups added with pay items, payslip history and payslip emails
-- Target:    PostgreSQL
-- Date:      2026-10-20
-- Ref:       App\Models\PayrollRun::getIssuedLinesForEmployee(), App\Models\ClientPayItem::listForClient(),
--            App\Models\ClientPayItem::deletePayItem()
--
--   payroll_run_lines (client_id, employee_id)  — one employee's payslips, month by month. Without it every
--                                                 payslip history reads all of the Client's lines.
--   employee_pay_item_amounts (pay_item_id)      — how many employees hold a pay item (the pay items list), and
--                                                 the ON DELETE RESTRICT check when one is deleted. The primary
--                                                 key starts with employee_id, so it cannot serve either.
--
-- Idempotent: safe to re-run.
-- =============================================================================

CREATE INDEX IF NOT EXISTS idx_payroll_run_lines_client_employee ON payroll_run_lines (client_id, employee_id);
CREATE INDEX IF NOT EXISTS idx_employee_pay_item_amounts_item ON employee_pay_item_amounts (pay_item_id);
