// The SSNIT contribution statement of one payroll run: each employee's employee and employer contributions,
// and how they split between Tier 1 (paid to SSNIT) and Tier 2 (paid to the trustee), with any voluntary Tier 3.

import { formatPercent } from "../lib/formatting";
import { differenceOf, sumOf, totalOver } from "../lib/payrollLines";
import type { PayrollRun, PayrollRunLine } from "../types";
import { RunStatementSheet } from "./RunStatementSheet";
import type { StatementColumn } from "./RunStatementSheet";

function contributionColumns(run: PayrollRun, lines: PayrollRunLine[]): StatementColumn[] {
  const totalSsnitPercent = sumOf(run.employee_ssnit_percent, run.employer_ssnit_percent);
  const columns: StatementColumn[] = [
    { key: "basic_salary", heading: "Basic salary", valueOf: (line) => line.basic_salary, total: run.total_basic_salary },
    {
      key: "employee_ssnit",
      heading: `Employee (${formatPercent(run.employee_ssnit_percent)}%)`,
      valueOf: (line) => line.employee_ssnit,
      total: run.total_employee_ssnit,
    },
    {
      key: "employer_ssnit",
      heading: `Employer (${formatPercent(run.employer_ssnit_percent)}%)`,
      valueOf: (line) => line.employer_ssnit,
      total: run.total_employer_ssnit,
    },
    {
      key: "total_ssnit",
      heading: `Total (${formatPercent(totalSsnitPercent)}%)`,
      valueOf: (line) => sumOf(line.employee_ssnit, line.employer_ssnit),
      total: totalOver(lines, (line) => sumOf(line.employee_ssnit, line.employer_ssnit)),
    },
    {
      key: "ssnit_tier1",
      heading: `Tier 1 (${formatPercent(run.ssnit_tier1_percent)}%)`,
      valueOf: (line) => line.ssnit_tier1,
      total: run.total_ssnit_tier1,
    },
    {
      key: "ssnit_tier2",
      heading: `Tier 2 (${formatPercent(differenceOf(totalSsnitPercent, run.ssnit_tier1_percent))}%)`,
      valueOf: (line) => line.ssnit_tier2,
      total: run.total_ssnit_tier2,
    },
  ];
  if (Number(run.total_tier3_contribution) > 0) {
    columns.push({
      key: "tier3_contribution",
      heading: "Tier 3 (voluntary)",
      valueOf: (line) => line.tier3_contribution,
      total: run.total_tier3_contribution,
    });
  }
  return columns;
}

export function ContributionStatementPage() {
  return (
    <RunStatementSheet
      title="SSNIT CONTRIBUTION STATEMENT"
      excelPath="export/contributions"
      employerIdentifier={(client) => ({ label: "Employer no.", value: client.ssnit_employer_number })}
      textColumns={[
        { key: "ssnit_number", heading: "SSNIT number", valueOf: (line) => line.ssnit_number },
        { key: "employee_name", heading: "Name", valueOf: (line) => line.employee_name },
      ]}
      figureColumns={contributionColumns}
    />
  );
}
