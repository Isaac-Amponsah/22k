// Payslips: printable A4 sheets, one employee per sheet, for a finalised or paid run.
// Rendered outside the app shell. The sheet carries no app header or footer: the PAYSLIP heading and
// the pay month are the document's own content. Add ?line=<id> for one employee's payslip.

import { useQuery } from "@tanstack/react-query";
import { useParams, useSearchParams } from "react-router-dom";
import { api, errorMessage } from "../lib/apiClient";
import { formatDate, formatMoney, formatPayMonth, formatPercent } from "../lib/formatting";
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
    padding: 0.7in 0.75in;
    background: #ffffff;
    box-shadow: 0 10px 30px rgb(22 32 46 / 0.14);
    font-size: 10.5pt;
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

  const employerName = clientsQuery.data?.find((client) => client.client_id === clientId)?.client_name ?? "";
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
        <PayslipSheet key={line.payroll_run_line_id} run={run} line={line} employerName={employerName} />
      ))}
    </div>
  );
}

function PayslipSheet({ run, line, employerName }: { run: PayrollRun; line: PayrollRunLine; employerName: string }) {
  return (
    <article className="payslip-sheet">
      <header className="flex items-end justify-between gap-6 border-b-2 border-[#16202e] pb-3">
        <div>
          <h1 className="text-[22pt] leading-none font-bold tracking-[0.14em]">PAYSLIP</h1>
          {employerName ? <p className="mt-1.5 font-semibold">{employerName}</p> : null}
        </div>
        <dl className="text-right">
          <div className="flex justify-end gap-2.5">
            <dt className="text-[#55606f]">Pay month</dt>
            <dd className="min-w-[34mm] font-semibold">{formatPayMonth(run.pay_period)}</dd>
          </div>
          {run.paid_at ? (
            <div className="flex justify-end gap-2.5">
              <dt className="text-[#55606f]">Paid on</dt>
              <dd className="min-w-[34mm] font-semibold">{formatDate(run.paid_at)}</dd>
            </div>
          ) : null}
        </dl>
      </header>

      <section className="my-5 flex gap-10">
        <div>
          <p className="text-[8pt] font-bold text-[#55606f]">Employee</p>
          <p className="text-[12pt] font-bold">{line.employee_name}</p>
          {line.employee_code ? <p>{line.employee_code}</p> : null}
        </div>
        {line.job_title_name || line.department_name ? (
          <div>
            <p className="text-[8pt] font-bold text-[#55606f]">Position</p>
            <p>{line.job_title_name}</p>
            <p>{line.department_name}</p>
          </div>
        ) : null}
      </section>

      <table className="w-full border-separate border-spacing-0">
        <thead>
          <tr className="text-[8pt] text-[#55606f]">
            <th className="border-y border-[#16202e] px-2.5 py-2 text-left font-bold">Description</th>
            <th className="figure border-y border-[#16202e] px-2.5 py-2 font-bold">Amount (GHS)</th>
          </tr>
        </thead>
        <tbody>
          <PayslipRow label="Basic salary" amount={formatMoney(line.basic_salary)} />
          <PayslipRow
            label={`Less SSNIT (${formatPercent(run.employee_ssnit_percent)}%)`}
            amount={`(${formatMoney(line.employee_ssnit)})`}
            isDeduction
          />
          <PayslipRow label="Basic salary less SSNIT" amount={formatMoney(line.basic_less_ssnit)} />
          <PayslipRow label="Allowance" amount={formatMoney(line.allowance)} />
          <PayslipRow label="Chargeable income" amount={formatMoney(line.chargeable_income)} isSubtotal />
          <PayslipRow label="Less PAYE" amount={`(${formatMoney(line.paye)})`} isDeduction />
          <tr className="font-bold">
            <td className="border-y-2 border-[#16202e] px-2.5 py-3">Take home</td>
            <td className="figure border-y-2 border-[#16202e] px-2.5 py-3 text-[14pt]">GHS {formatMoney(line.net_pay)}</td>
          </tr>
        </tbody>
      </table>

      <p className="mt-3.5 text-[9pt] text-[#55606f]">
        Employer SSNIT contribution ({formatPercent(run.employer_ssnit_percent)}%), paid on top of the above: GHS{" "}
        {formatMoney(line.employer_ssnit)}
      </p>

      <footer className="mt-14 flex justify-between gap-10 text-[9pt] text-[#55606f]">
        <div className="w-[62mm] border-t border-[#16202e] pt-1.5">Authorised signatory</div>
        <div className="w-[62mm] border-t border-[#16202e] pt-1.5">Employee</div>
      </footer>
    </article>
  );
}

interface PayslipRowProps {
  label: string;
  amount: string;
  isDeduction?: boolean;
  isSubtotal?: boolean;
}

function PayslipRow({ label, amount, isDeduction = false, isSubtotal = false }: PayslipRowProps) {
  const cellClasses = `border-b border-[#c9cfd8] px-2.5 py-2 ${isSubtotal ? "bg-[#f3f5f8] font-semibold" : ""}`;
  return (
    <tr>
      <td className={`${cellClasses} ${isDeduction ? "pl-[22px]" : ""}`}>{label}</td>
      <td className={`figure ${cellClasses}`}>{amount}</td>
    </tr>
  );
}
