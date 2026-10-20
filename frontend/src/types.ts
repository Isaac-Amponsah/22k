// Shapes of the API's answers. Postgres numerics arrive as strings ("4000.00"); format with lib/formatting.

export interface SignedInUser {
  user_id: number;
  email: string;
  first_name: string;
  last_name: string;
}

export interface Client {
  client_id: number;
  client_name: string;
  tax_identification_number: string | null;
  ssnit_employer_number: string | null;
  contact_email: string | null;
  contact_phone: string | null;
  postal_address: string | null;
  is_archived: boolean;
}

export interface Employee {
  employee_id: number;
  employee_code: string | null;
  first_name: string;
  last_name: string;
  job_title_name: string | null;
  department_name: string | null;
  hire_date: string | null;
  ssnit_number: string | null;
  tax_identification_number: string | null;
  bank_account_number: string | null;
  bank_name: string | null;
  bank_branch: string | null;
  bank_sort_code: string | null;
  email_address: string | null;
  is_active: boolean;
}

export interface TaxBand {
  band_order: number;
  band_width: string | null;
  rate_percent: string;
}

/** A personal relief a rate set allows: a fixed annual amount per claim, or a share of assessable income. */
export interface ReliefRate {
  annual_amount: string | null;
  percent_of_assessable: string | null;
  max_claims: number;
}

export interface RateSet {
  statutory_rate_id: number;
  effective_from: string;
  employee_ssnit_percent: string;
  employer_ssnit_percent: string;
  /** Share of the employee + employer SSNIT paid to SSNIT; the rest is Tier 2. */
  ssnit_tier1_percent: string;
  /** Most of basic salary a voluntary Tier 3 contribution is relieved from tax on. */
  tier3_relief_cap_percent: string;
  note: string | null;
  bands: TaxBand[];
  /** Keyed by relief code; a relief missing here cannot be claimed under the set. */
  reliefs: Record<string, ReliefRate>;
}

export type PayItemKind = "allowance" | "deduction";

/** One of a client's allowance or deduction types. */
export interface PayItem {
  pay_item_id: number;
  item_kind: PayItemKind;
  item_name: string;
  /** Allowances only: whether it is part of chargeable income. */
  is_taxable: boolean;
  display_order: number;
  /** Employees holding an amount on it. */
  employee_count: number;
}

export interface ReliefType {
  relief_code: string;
  label: string;
}

/** An allowance, deduction or relief on a worked-out line, as it was named that month. */
export interface PayLineItem {
  item_kind: PayItemKind | "relief";
  item_name: string;
  /** Allowances only. */
  is_taxable: boolean | null;
  amount: number | string;
}

export interface PayFigures {
  basic_salary: number;
  employee_ssnit: number;
  basic_less_ssnit: number;
  allowance: number;
  taxable_allowance: number;
  tier3_contribution: number;
  tier3_relief: number;
  personal_relief: number;
  chargeable_income: number;
  paye: number;
  other_deductions: number;
  net_pay: number;
  employer_ssnit: number;
  ssnit_tier1: number;
  ssnit_tier2: number;
  items: PayLineItem[];
}

export interface SalariedEmployee {
  employee_id: number;
  employee_code: string | null;
  first_name: string;
  last_name: string;
  job_title_name: string | null;
  employee_salary_id: number | null;
  basic_salary: string | null;
  tier3_percent: string | null;
  /** Keyed by pay_item_id. */
  pay_item_amounts: Record<string, number>;
  /** Keyed by relief code: how many times it is claimed. */
  relief_claims: Record<string, number>;
  pay: PayFigures | null;
  pay_error?: string;
}

export type PayrollRunStatus = "draft" | "finalised" | "paid";

export interface PayrollRunLine {
  payroll_run_line_id: number;
  employee_id: number;
  employee_name: string;
  employee_code: string | null;
  job_title_name: string | null;
  department_name: string | null;
  ssnit_number: string | null;
  tax_identification_number: string | null;
  basic_salary: string;
  employee_ssnit: string;
  basic_less_ssnit: string;
  allowance: string;
  taxable_allowance: string;
  tier3_contribution: string;
  tier3_relief: string;
  personal_relief: string;
  chargeable_income: string;
  paye: string;
  other_deductions: string;
  net_pay: string;
  employer_ssnit: string;
  ssnit_tier1: string;
  ssnit_tier2: string;
  items: PayLineItem[];
}

export interface PayrollRun {
  payroll_run_id: number;
  pay_period: string;
  status: PayrollRunStatus;
  employee_ssnit_percent: string;
  employer_ssnit_percent: string;
  ssnit_tier1_percent: string;
  employee_count: number;
  total_basic_salary: string;
  total_employee_ssnit: string;
  total_basic_less_ssnit: string;
  total_allowance: string;
  total_taxable_allowance: string;
  total_tier3_contribution: string;
  total_tier3_relief: string;
  total_personal_relief: string;
  total_chargeable_income: string;
  total_paye: string;
  total_other_deductions: string;
  total_net_pay: string;
  total_employer_ssnit: string;
  total_ssnit_tier1: string;
  total_ssnit_tier2: string;
  notes: string | null;
  created_by_name?: string | null;
  finalised_by_name?: string | null;
  finalised_at: string | null;
  paid_by_name?: string | null;
  paid_at: string | null;
  lines?: PayrollRunLine[];
}

/** What the dashboard shows of a client's run for one month. */
export interface PeriodRunSummary {
  payroll_run_id: number;
  pay_period: string;
  status: PayrollRunStatus;
  employee_count: number;
  total_paye: string;
  total_net_pay: string;
  total_employee_ssnit: string;
  total_employer_ssnit: string;
}

export interface MonthOverviewClient {
  client_id: number;
  client_name: string;
  is_archived: boolean;
  active_employee_count: number;
  period_run: PeriodRunSummary | null;
}

/** One month across all of the accountant's clients. */
export interface MonthOverview {
  pay_period: string;
  /** The furthest month a run may be prepared for, as "YYYY-MM". */
  latest_month: string;
  clients: MonthOverviewClient[];
  totals: {
    total_net_pay: string;
    total_paye: string;
    /** Employee and employer contributions together. */
    total_ssnit: string;
    employees_paid: number;
  };
}

/** A client's bank, who at the bank receives its payroll, and the email template with {placeholders}. */
export interface BankEmailSettings {
  bank_name: string | null;
  recipient_emails: string[];
  email_subject_template: string;
  email_body_template: string;
}

export interface SentBankEmail {
  payroll_run_bank_email_id: number;
  recipient_emails: string[];
  email_subject: string;
  sent_at: string;
  sent_by_name: string | null;
}

/** One month's payslip of an employee: their line of a finalised or paid run, with what the run was worked out at. */
export type EmployeePayslip = PayrollRunLine &
  Pick<PayrollRun, "payroll_run_id" | "pay_period" | "status" | "paid_at" | "employee_ssnit_percent" | "employer_ssnit_percent" | "ssnit_tier1_percent">;

export interface PayslipRecipient {
  payroll_run_line_id: number;
  employee_name: string;
  /** The employee's address as it is now. */
  email_address: string | null;
  /** Where their payslip went, once it has been emailed. */
  sent_to_email: string | null;
  payslip_sent_at: string | null;
}

/** Who in a run gets their payslip by email, and who has had it. */
export interface RunPayslipEmails {
  recipients: PayslipRecipient[];
  employees_missing_email: string[];
  /** Employees with an address who have not been emailed their payslip yet. */
  unsent_count: number;
  is_mail_configured: boolean;
}

/** The email as it would go out for one payroll run, and what has been sent for it already. */
export interface RunBankEmail {
  recipient_emails: string[];
  email_subject: string;
  email_body: string;
  attachment_filename: string;
  /** Names of employees the run pays whose account number or bank is not recorded: the bank could not pay them. */
  employees_missing_account_details: string[];
  /** False when the server has no mail server set up: nothing can be sent. */
  is_mail_configured: boolean;
  sent_emails: SentBankEmail[];
}

export interface ImportedSalaryRow {
  sheet_row: number;
  employee_name: string;
  basic_salary: number;
  allowance: number;
  chargeable_income: number | null;
  matched_employee_id: number | null;
}
