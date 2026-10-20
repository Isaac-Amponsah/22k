// The PAYE tax analysis of one payroll run: how each employee's PAYE was reached, from assessable income
// (basic less SSNIT plus taxable allowances) through Tier 3 and personal reliefs to chargeable income.

import { formatPercent } from "../lib/formatting";
import { differenceOf, sumOf, totalOver } from "../lib/payrollLines";
import type { PayrollRun, PayrollRunLine } from "../types";
import { RunStatementSheet } from "./RunStatementSheet";
import type { StatementColumn } from "./RunStatementSheet";

function taxAnalysisColumns(run: PayrollRun, lines: PayrollRunLine[]): StatementColumn[] {
  const nonTaxableAllowanceOf = (line: PayrollRunLine) => differenceOf(line.allowance, line.taxable_allowance);
  const assessableIncomeOf = (line: PayrollRunLine) => sumOf(line.basic_less_ssnit, line.taxable_allowance);
  return [
    { key: "basic_salary", heading: "Basic salary", valueOf: (line) => line.basic_salary, total: run.total_basic_salary },
    {
      key: "employee_ssnit",
      heading: `SSNIT (${formatPercent(run.employee_ssnit_percent)}%)`,
      valueOf: (line) => line.employee_ssnit,
      total: run.total_employee_ssnit,
    },
    { key: "taxable_allowance", heading: "Taxable allowances", valueOf: (line) => line.taxable_allowance, total: run.total_taxable_allowance },
    {
      key: "non_taxable_allowance",
      heading: "Non-taxable allowances",
      valueOf: nonTaxableAllowanceOf,
      total: totalOver(lines, nonTaxableAllowanceOf),
    },
    { key: "assessable_income", heading: "Assessable income", valueOf: assessableIncomeOf, total: totalOver(lines, assessableIncomeOf) },
    { key: "tier3_relief", heading: "Tier 3 relief", valueOf: (line) => line.tier3_relief, total: run.total_tier3_relief },
    { key: "personal_relief", heading: "Personal relief", valueOf: (line) => line.personal_relief, total: run.total_personal_relief },
    { key: "chargeable_income", heading: "Chargeable income", valueOf: (line) => line.chargeable_income, total: run.total_chargeable_income },
    { key: "paye", heading: "PAYE", valueOf: (line) => line.paye, total: run.total_paye },
  ];
}

export function TaxAnalysisPage() {
  return (
    <RunStatementSheet
      title="PAYE TAX ANALYSIS"
      excelPath="export/tax-analysis"
      employerIdentifier={(client) => ({ label: "TIN", value: client.tax_identification_number })}
      textColumns={[
        { key: "tax_identification_number", heading: "TIN", valueOf: (line) => line.tax_identification_number },
        { key: "employee_name", heading: "Name", valueOf: (line) => line.employee_name },
      ]}
      figureColumns={taxAnalysisColumns}
    />
  );
}
