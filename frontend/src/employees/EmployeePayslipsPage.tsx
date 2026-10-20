// One employee's payslips, month by month: what each month paid, and its breakdown — earnings, deductions,
// how the PAYE was reached and the SSNIT contributions by tier.

import { Fragment, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useParams } from "react-router-dom";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage } from "../lib/apiClient";
import { formatMoney, formatPayMonth, formatPercent, fullName } from "../lib/formatting";
import { differenceOf, sumOf } from "../lib/payrollLines";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { ActionsMenu } from "../shared/ActionsMenu";
import { EmptyState, LoadingState, Notice, PageHeading, Panel, RunStatusBadge } from "../shared/ui";
import type { Employee, EmployeePayslip } from "../types";

const LINK_BUTTON_CLASSES =
  "inline-flex items-center justify-center rounded-md border border-rule bg-surface px-3 py-1.5 text-sm font-medium hover:bg-brand-tint";

export function EmployeePayslipsPage() {
  const client = useClientInScope();
  const employeeId = Number(useParams().employeeId);
  const [openPayslipLineId, setOpenPayslipLineId] = useState<number | null>(null);

  const payslipsQuery = useQuery({
    queryKey: queryKeys.employeePayslips(client.client_id, employeeId),
    queryFn: async () =>
      (await api.get<{ employee: Employee; payslips: EmployeePayslip[] }>(clientApiPath(client.client_id, `employees/${employeeId}/payslips`)))
        .data,
  });

  const backLink = (
    <Link to="../.." relative="path" className={LINK_BUTTON_CLASSES}>
      All employees
    </Link>
  );

  if (payslipsQuery.isPending) {
    return <LoadingState />;
  }
  if (payslipsQuery.isError) {
    return (
      <>
        <PageHeading title="Payslips" actions={backLink} />
        <Notice tone="refusal">{errorMessage(payslipsQuery.error)}</Notice>
      </>
    );
  }

  const { employee, payslips } = payslipsQuery.data;

  return (
    <>
      <PageHeading title={`Payslips of ${fullName(employee)}`} actions={backLink} />

      <Panel>
        {payslips.length === 0 ? (
          <EmptyState>No finalised payroll has paid this employee yet.</EmptyState>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-rule text-ink-soft">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">Month</th>
                  <th className="figure px-4 py-3 font-medium">Gross pay</th>
                  <th className="figure px-4 py-3 font-medium">SSNIT</th>
                  <th className="figure px-4 py-3 font-medium">PAYE</th>
                  <th className="figure px-4 py-3 font-medium">Other deductions</th>
                  <th className="figure px-4 py-3 font-medium">Take home</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody>
                {payslips.map((payslip) => {
                  const isOpen = openPayslipLineId === payslip.payroll_run_line_id;
                  const payslipPath = `/clients/${client.client_id}/payroll/${payslip.payroll_run_id}/payslips?line=${payslip.payroll_run_line_id}`;
                  return (
                    <Fragment key={payslip.payroll_run_line_id}>
                      <tr className="border-b border-rule/60">
                        <td className="px-4 py-3">
                          <span className="font-medium">{formatPayMonth(payslip.pay_period)}</span>{" "}
                          <RunStatusBadge status={payslip.status} />
                        </td>
                        <td className="figure px-4 py-3">{formatMoney(sumOf(payslip.basic_salary, payslip.allowance))}</td>
                        <td className="figure px-4 py-3">{formatMoney(sumOf(payslip.employee_ssnit, payslip.tier3_contribution))}</td>
                        <td className="figure px-4 py-3">{formatMoney(payslip.paye)}</td>
                        <td className="figure px-4 py-3">{formatMoney(payslip.other_deductions)}</td>
                        <td className="figure px-4 py-3 font-semibold">{formatMoney(payslip.net_pay)}</td>
                        <td className="px-4 py-2 text-right">
                          <ActionsMenu
                            label={`Actions for ${formatPayMonth(payslip.pay_period)}`}
                            actions={[
                              {
                                label: isOpen ? "Hide breakdown" : "Show breakdown",
                                onSelect: () => setOpenPayslipLineId(isOpen ? null : payslip.payroll_run_line_id),
                              },
                              { label: "Open payslip", to: payslipPath, opensInNewTab: true },
                              // A plain link: the browser downloads the PDF with the session cookie.
                              {
                                label: "Download PDF",
                                href: clientApiPath(client.client_id, `payroll-runs/${payslip.payroll_run_id}/payslips/${payslip.payroll_run_line_id}/pdf`),
                              },
                            ]}
                          />
                        </td>
                      </tr>
                      {isOpen ? (
                        <tr className="border-b border-rule/60 bg-paper/60">
                          <td colSpan={7} className="px-4 py-4">
                            <PayslipBreakdown payslip={payslip} />
                          </td>
                        </tr>
                      ) : null}
                    </Fragment>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </>
  );
}

interface BreakdownRow {
  label: string;
  amount: number | string;
  isTotal?: boolean;
}

function PayslipBreakdown({ payslip }: { payslip: EmployeePayslip }) {
  const itemsOf = (itemKind: "allowance" | "deduction" | "relief") => payslip.items.filter((lineItem) => lineItem.item_kind === itemKind);
  const tier2Percent = differenceOf(sumOf(payslip.employee_ssnit_percent, payslip.employer_ssnit_percent), payslip.ssnit_tier1_percent);
  const hasTier3 = Number(payslip.tier3_contribution) > 0;

  const earnings: BreakdownRow[] = [
    { label: "Basic salary", amount: payslip.basic_salary },
    ...itemsOf("allowance").map((allowance) => ({
      label: allowance.is_taxable === false ? `${allowance.item_name} (non-taxable)` : allowance.item_name,
      amount: allowance.amount,
    })),
    { label: "Gross pay", amount: sumOf(payslip.basic_salary, payslip.allowance), isTotal: true },
  ];
  const deductions: BreakdownRow[] = [
    { label: `SSNIT (${formatPercent(payslip.employee_ssnit_percent)}%)`, amount: payslip.employee_ssnit },
    ...(hasTier3 ? [{ label: "Tier 3 (voluntary)", amount: payslip.tier3_contribution }] : []),
    { label: "PAYE", amount: payslip.paye },
    ...itemsOf("deduction").map((deduction) => ({ label: deduction.item_name, amount: deduction.amount })),
    {
      label: "Total deductions",
      amount: sumOf(payslip.employee_ssnit, payslip.tier3_contribution, payslip.paye, payslip.other_deductions),
      isTotal: true,
    },
    { label: "Take home", amount: payslip.net_pay, isTotal: true },
  ];
  const taxAnalysis: BreakdownRow[] = [
    { label: "Assessable income", amount: sumOf(payslip.basic_less_ssnit, payslip.taxable_allowance) },
    ...(Number(payslip.tier3_relief) > 0 ? [{ label: "Tier 3 relief", amount: payslip.tier3_relief }] : []),
    ...itemsOf("relief").map((relief) => ({ label: `${relief.item_name} relief`, amount: relief.amount })),
    { label: "Chargeable income", amount: payslip.chargeable_income, isTotal: true },
    { label: "PAYE", amount: payslip.paye },
  ];
  const ssnitContributions: BreakdownRow[] = [
    { label: `Employee (${formatPercent(payslip.employee_ssnit_percent)}%)`, amount: payslip.employee_ssnit },
    { label: `Employer (${formatPercent(payslip.employer_ssnit_percent)}%)`, amount: payslip.employer_ssnit },
    { label: `Tier 1 to SSNIT (${formatPercent(payslip.ssnit_tier1_percent)}%)`, amount: payslip.ssnit_tier1 },
    { label: `Tier 2 to trustee (${formatPercent(tier2Percent)}%)`, amount: payslip.ssnit_tier2 },
    ...(hasTier3 ? [{ label: "Tier 3 (voluntary)", amount: payslip.tier3_contribution }] : []),
  ];

  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <BreakdownList heading="Allowances & earnings" rows={earnings} />
      <BreakdownList heading="Deductions" rows={deductions} />
      <BreakdownList heading="Tax analysis" rows={taxAnalysis} />
      <BreakdownList heading="SSNIT contributions" rows={ssnitContributions} />
    </div>
  );
}

function BreakdownList({ heading, rows }: { heading: string; rows: BreakdownRow[] }) {
  return (
    <section className="rounded-md border border-rule bg-surface">
      <h3 className="border-b border-rule px-3 py-2 text-xs font-semibold text-ink-soft">{heading}</h3>
      <dl className="text-sm">
        {rows.map((row) => (
          <div key={row.label} className={`flex justify-between gap-3 px-3 py-1.5 ${row.isTotal ? "border-t border-rule font-semibold" : ""}`}>
            <dt>{row.label}</dt>
            <dd className="figure">{formatMoney(row.amount)}</dd>
          </div>
        ))}
      </dl>
    </section>
  );
}
