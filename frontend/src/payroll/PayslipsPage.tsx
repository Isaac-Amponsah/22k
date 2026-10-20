// Payslips: printable A4 sheets, one employee per sheet, for a finalised or paid run.
// Rendered outside the app shell. The sheet carries no app header or footer: the PAYSLIP heading and
// the pay month are the document's own content. Add ?line=<id> for one employee's payslip.

import { useQuery } from "@tanstack/react-query";
import { useParams, useSearchParams } from "react-router-dom";
import { api, errorMessage } from "../lib/apiClient";
import { formatDate, formatMoney, formatPayMonth, formatPercent } from "../lib/formatting";
import { differenceOf, sumOf } from "../lib/payrollLines";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { Button, EmptyState, LoadingState } from "../shared/ui";
import type { Client, PayrollRun, PayrollRunLine } from "../types";

// Zero @page margins so the browser reserves no band for its own header (title, date) or footer
// (address, page number). The real margins live on .payslip-sheet instead. Mounted only with this page.
const PAYSLIP_PRINT_STYLES = `
  @page { size: A4; margin: 0; }
  html, body { background: #e9edf2; }
  .payslip-sheet {
    width: 210mm;
    min-height: 148mm;
    margin: 0 auto 24px;
    padding: 0.55in 0.65in;
    background: #ffffff;
    box-shadow: 0 10px 30px rgb(22 32 46 / 0.14);
    font-size: 9.5pt;
    line-height: 1.45;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  @media print {
    html, body { background: #ffffff; }
    .payslip-sheet { width: auto; min-height: 0; margin: 0; box-shadow: none; break-after: page; }
    .payslip-sheet:last-child { break-after: auto; }
  }
`;

export function PayslipsPage() {
  const routeParameters = useParams();
  const clientId = Number(routeParameters.clientId);
  const payrollRunId = Number(routeParameters.payrollRunId);
  const [searchParameters] = useSearchParams();
  const onlyLineId = searchParameters.get("line");

  const clientsQuery = useQuery({
    queryKey: queryKeys.clients(),
    queryFn: async () => (await api.get<{ clients: Client[] }>("/api/clients")).data.clients,
  });
  const runQuery = useQuery({
    queryKey: queryKeys.payrollRun(clientId, payrollRunId),
    queryFn: async () => (await api.get<{ run: PayrollRun }>(clientApiPath(clientId, `payroll-runs/${payrollRunId}`))).data.run,
  });

  if (runQuery.isPending || clientsQuery.isPending) {
    return <LoadingState />;
  }
  if (runQuery.isError) {
    return <EmptyState>{errorMessage(runQuery.error)}</EmptyState>;
  }

  const run = runQuery.data;
  // Payslips are issued from frozen figures only: a draft's lines can still change.
  if (run.status === "draft") {
    return <EmptyState>Finalise the payroll run before printing payslips.</EmptyState>;
  }

  const employer = clientsQuery.data?.find((client) => client.client_id === clientId);
  const lines = (run.lines ?? []).filter((line) => onlyLineId === null || String(line.payroll_run_line_id) === onlyLineId);
  if (lines.length === 0) {
    return <EmptyState>Payslip not found.</EmptyState>;
  }

  return (
    <div className="text-[#16202e]">
      <style>{PAYSLIP_PRINT_STYLES}</style>

      <div className="screen-only sticky top-0 z-10 flex justify-center gap-2 bg-[#e9edf2] p-3">
        <Button variant="primary" onClick={() => window.print()}>
          Print or save as PDF
        </Button>
        <Button onClick={() => window.close()}>Close</Button>
      </div>

      {lines.map((line) => (
        <PayslipSheet key={line.payroll_run_line_id} run={run} line={line} employer={employer} />
      ))}
    </div>
  );
}

function PayslipSheet({ run, line, employer }: { run: PayrollRun; line: PayrollRunLine; employer: Client | undefined }) {
  const itemsOf = (itemKind: "allowance" | "deduction" | "relief") => line.items.filter((lineItem) => lineItem.item_kind === itemKind);
  const hasTier3 = Number(line.tier3_contribution) > 0;
  const reliefs = itemsOf("relief");
  const tier2Percent = differenceOf(sumOf(run.employee_ssnit_percent, run.employer_ssnit_percent), run.ssnit_tier1_percent);

  const earnings: LedgerEntry[] = [
    { label: "Basic salary", amount: line.basic_salary },
    ...itemsOf("allowance").map((allowance) => ({
      label: allowance.item_name,
      amount: allowance.amount,
      tag: allowance.is_taxable === false ? "non-taxable" : undefined,
    })),
  ];
  const deductions: LedgerEntry[] = [
    { label: `SSNIT (${formatPercent(run.employee_ssnit_percent)}%)`, amount: line.employee_ssnit },
    ...(hasTier3 ? [{ label: "Tier 3 pension", amount: line.tier3_contribution, tag: "voluntary" }] : []),
    { label: "PAYE", amount: line.paye },
    ...itemsOf("deduction").map((deduction) => ({ label: deduction.item_name, amount: deduction.amount })),
  ];
  const ledgerRowCount = Math.max(earnings.length, deductions.length);

  const taxCells: StripCell[] = [
    { label: "Assessable income", amount: sumOf(line.basic_less_ssnit, line.taxable_allowance) },
    ...(Number(line.tier3_relief) > 0 ? [{ label: "Tier 3 relief", amount: line.tier3_relief }] : []),
    ...(reliefs.length > 0
      ? [{ label: "Personal relief", amount: line.personal_relief, note: reliefs.map((relief) => relief.item_name).join(", ") }]
      : []),
    { label: "Chargeable income", amount: line.chargeable_income, isResult: true },
    { label: "PAYE", amount: line.paye, isResult: true },
  ];
  const ssnitCells: StripCell[] = [
    { label: `Employee ${formatPercent(run.employee_ssnit_percent)}%`, amount: line.employee_ssnit },
    { label: `Employer ${formatPercent(run.employer_ssnit_percent)}%`, amount: line.employer_ssnit },
    { label: `Tier 1 ${formatPercent(run.ssnit_tier1_percent)}%`, amount: line.ssnit_tier1, note: "to SSNIT", isResult: true },
    { label: `Tier 2 ${formatPercent(tier2Percent)}%`, amount: line.ssnit_tier2, note: "to trustee", isResult: true },
    ...(hasTier3 ? [{ label: "Tier 3", amount: line.tier3_contribution, note: "voluntary", isResult: true }] : []),
  ];

  const position = [line.job_title_name, line.department_name].filter(Boolean).join(", ");
  const employeeFields = [
    { label: "Employee no.", value: line.employee_code },
    { label: "Position", value: position },
    { label: "SSNIT no.", value: line.ssnit_number },
    { label: "TIN", value: line.tax_identification_number },
  ].filter((employeeField) => employeeField.value);

  return (
    <article className="payslip-sheet">
      <header className="flex items-end justify-between gap-6 border-b-2 border-[#16202e] pb-2.5">
        <div>
          {employer?.client_name ? <p className="text-[13pt] leading-tight font-bold tracking-[0.06em] uppercase">{employer.client_name}</p> : null}
          {employer?.ssnit_employer_number ? <p className={`mt-1 ${CAPTION_CLASSES}`}>Employer SSNIT no. {employer.ssnit_employer_number}</p> : null}
        </div>
        <div className="text-right">
          <h1 className="text-[17pt] leading-none font-bold tracking-[0.25em]">PAYSLIP</h1>
          <p className="mt-1 text-[10.5pt] font-bold">{formatPayMonth(run.pay_period)}</p>
          {run.paid_at ? <p className={CAPTION_CLASSES}>Paid on {formatDate(run.paid_at)}</p> : null}
        </div>
      </header>

      <section className="mt-3 flex flex-wrap gap-x-8 gap-y-2 bg-[#f3f5f8] px-3 py-2.5">
        <div>
          <p className={CAPTION_CLASSES}>Employee</p>
          <p className="text-[11pt] font-bold">{line.employee_name}</p>
        </div>
        {employeeFields.map((employeeField) => (
          <div key={employeeField.label}>
            <p className={CAPTION_CLASSES}>{employeeField.label}</p>
            <p>{employeeField.value}</p>
          </div>
        ))}
      </section>

      <table className="mt-3 w-full table-fixed border-collapse border border-[#16202e]">
        <colgroup>
          <col className="w-[32%]" />
          <col className="w-[18%]" />
          <col className="w-[32%]" />
          <col className="w-[18%]" />
        </colgroup>
        <thead>
          <tr className={`bg-[#f3f5f8] ${CAPTION_CLASSES}`}>
            <th className="border-b border-[#16202e] px-2.5 py-1.5 text-left">Earnings</th>
            <th className="figure border-b border-[#16202e] px-2.5 py-1.5">GHS</th>
            <th className="border-b border-l border-[#16202e] px-2.5 py-1.5 text-left">Deductions</th>
            <th className="figure border-b border-[#16202e] px-2.5 py-1.5">GHS</th>
          </tr>
        </thead>
        <tbody>
          {Array.from({ length: ledgerRowCount }, (_, rowIndex) => (
            <tr key={rowIndex}>
              <LedgerCells entry={earnings[rowIndex]} />
              <LedgerCells entry={deductions[rowIndex]} isRightSide />
            </tr>
          ))}
          <tr className="font-bold">
            <td className="border-t border-[#16202e] px-2.5 py-1.5">Gross pay</td>
            <td className="figure border-t border-[#16202e] px-2.5 py-1.5">{formatMoney(sumOf(line.basic_salary, line.allowance))}</td>
            <td className="border-t border-l border-[#16202e] px-2.5 py-1.5">Total deductions</td>
            <td className="figure border-t border-[#16202e] px-2.5 py-1.5">
              {formatMoney(sumOf(line.employee_ssnit, line.tier3_contribution, line.paye, line.other_deductions))}
            </td>
          </tr>
        </tbody>
      </table>

      <div className="flex items-center justify-between bg-[#16202e] px-2.5 py-2.5 font-bold text-white">
        <span className="tracking-[0.2em]">NET PAY</span>
        <span className="text-[15pt]">GHS {formatMoney(line.net_pay)}</span>
      </div>

      <PayslipStrip heading="Tax analysis" cells={taxCells} />
      <PayslipStrip heading="SSNIT" cells={ssnitCells} />

      <footer className="mt-10 flex justify-between gap-10 text-[8pt] text-[#55606f]">
        <div className="w-[58mm] border-t border-[#16202e] pt-1">Authorised signatory</div>
        <div className="w-[58mm] border-t border-[#16202e] pt-1">Employee</div>
      </footer>
    </article>
  );
}

const CAPTION_CLASSES = "text-[7pt] font-bold tracking-[0.05em] text-[#55606f] uppercase";

interface LedgerEntry {
  label: string;
  amount: string | number;
  tag?: string;
}

/** One side of a ledger row; an empty side when that column has run out of entries. */
function LedgerCells({ entry, isRightSide = false }: { entry: LedgerEntry | undefined; isRightSide?: boolean }) {
  const labelClasses = `px-2.5 py-1 ${isRightSide ? "border-l border-[#16202e]" : ""}`;
  if (!entry) {
    return (
      <>
        <td className={labelClasses} />
        <td />
      </>
    );
  }
  return (
    <>
      <td className={labelClasses}>
        {entry.label}
        {entry.tag ? <span className="ml-1.5 text-[7pt] text-[#55606f]">{entry.tag}</span> : null}
      </td>
      <td className="figure px-2.5 py-1">{formatMoney(entry.amount)}</td>
    </>
  );
}

interface StripCell {
  label: string;
  amount: string | number;
  note?: string;
  /** What the strip arrives at: shown bold. */
  isResult?: boolean;
}

function PayslipStrip({ heading, cells }: { heading: string; cells: StripCell[] }) {
  return (
    <section className="mt-3 flex border border-[#c9cfd8]">
      <h2 className={`flex w-[22mm] shrink-0 items-center bg-[#f3f5f8] px-2.5 ${CAPTION_CLASSES}`}>{heading}</h2>
      {cells.map((cell) => (
        <div key={cell.label} className="flex-1 border-l border-[#c9cfd8] px-2.5 py-1.5">
          <p className={CAPTION_CLASSES}>{cell.label}</p>
          <p className={`text-[10pt] tabular-nums ${cell.isResult ? "font-bold" : ""}`}>{formatMoney(cell.amount)}</p>
          {cell.note ? <p className="text-[7pt] text-[#55606f]">{cell.note}</p> : null}
        </div>
      ))}
    </section>
  );
}
