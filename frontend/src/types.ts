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
  is_active: boolean;
}

export type AllowanceMode = "flat" | "target";

export interface TaxBand {
  band_order: number;
  band_width: string | null;
  rate_percent: string;
}

export interface RateSet {
  statutory_rate_id: number;
  effective_from: string;
  employee_ssnit_percent: string;
  employer_ssnit_percent: string;
  note: string | null;
  bands: TaxBand[];
}

export interface PayFigures {
  basic_salary: number;
  employee_ssnit: number;
  basic_less_ssnit: number;
  allowance: number;
  chargeable_income: number;
  paye: number;
  net_pay: number;
  employer_ssnit: number;
}

export interface SalariedEmployee {
  employee_id: number;
  employee_code: string | null;
  first_name: string;
  last_name: string;
  job_title_name: string | null;
  employee_salary_id: number | null;
  basic_salary: string | null;
  allowance_mode: AllowanceMode | null;
  flat_allowance: string | null;
  target_chargeable_income: string | null;
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
  basic_salary: string;
  employee_ssnit: string;
  basic_less_ssnit: string;
  allowance: string;
  chargeable_income: string;
  paye: string;
  net_pay: string;
  employer_ssnit: string;
}

export interface PayrollRun {
  payroll_run_id: number;
  pay_period: string;
  status: PayrollRunStatus;
  employee_ssnit_percent: string;
  employer_ssnit_percent: string;
  employee_count: number;
  total_basic_salary: string;
  total_employee_ssnit: string;
  total_basic_less_ssnit: string;
  total_allowance: string;
  total_chargeable_income: string;
  total_paye: string;
  total_net_pay: string;
  total_employer_ssnit: string;
  notes: string | null;
  created_by_name?: string | null;
  finalised_by_name?: string | null;
  finalised_at: string | null;
  paid_by_name?: string | null;
  paid_at: string | null;
  lines?: PayrollRunLine[];
}

export interface ImportedSalaryRow {
  sheet_row: number;
  employee_name: string;
  basic_salary: number;
  allowance: number;
  chargeable_income: number | null;
  allowance_mode: AllowanceMode;
  flat_allowance: number;
  target_chargeable_income: number | null;
  matched_employee_id: number | null;
}
