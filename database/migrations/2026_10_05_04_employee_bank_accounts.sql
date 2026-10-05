-- =============================================================================
-- Migration: where each employee's salary is paid
-- Target:    PostgreSQL
-- Date:      2026-10-05
-- Ref:       App\Models\Employee, App\Services\BankPaymentFileService
--
-- The file sent to a Client's bank lists, per employee: Name, AccountNumber, BankName, BankBranch,
-- SortCode, Amount. The account details live on the employee. Account number and sort code are
-- text: they are identifiers, and a sort code may start with 0.
--
-- employees already has its row-level security policy and grants; new columns fall under both.
--
-- Idempotent: safe to re-run.
-- =============================================================================

ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_account_number VARCHAR(30);
ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_name           VARCHAR(100);
ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_branch         VARCHAR(100);
ALTER TABLE employees ADD COLUMN IF NOT EXISTS bank_sort_code      VARCHAR(10);
