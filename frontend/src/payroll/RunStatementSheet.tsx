// A statement printed from one finalised or paid payroll run (SSNIT contributions, tax analysis): a landscape
// A4 sheet rendered outside the app shell, like payslips. The sheet carries no app header or footer: its
// heading, the employer and the pay month are the document's own content.

import { useQuery } from "@tanstack/react-query";
import { useParams } from "react-router-dom";
import { api, errorMessage } from "../lib/apiClient";
import { formatMoney, formatPayMonth } from "../lib/formatting";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { Button, EmptyState, LoadingState } from "../shared/ui";
import type { Client, PayrollRun, PayrollRunLine } from "../types";

// Zero @page margins so the browser reserves no band for its own header (title, date) or footer
// (address, page number). The real margins live on .statement-sheet instead. Mounted only with this page.
const STATEMENT_PRINT_STYLES = `
  @page { size: A4 landscape; margin: 0; }
  html, body { background: #e9edf2; }
  .statement-sheet {
    width: 297mm;
    min-height: 210mm;
    margin: 0 auto 24px;
    padding: 0.6in 0.6in;
    background: #ffffff;
    box-shadow: 0 10px 30px rgb(22 32 46 / 0.14);
    font-size: 9pt;
    line-height: 1.4;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
  }
  .statement-sheet thead { display: table-header-group; }
  .statement-sheet tr { break-inside: avoid; }
  @media print {
    html, body { background: #ffffff; }
    .statement-sheet { width: auto; min-height: 0; margin: 0; box-shadow: none; }
  }
`;

/** One figure column of a statement: its heading, each line's value, and the total row's. */
export interface StatementColumn {
  key: string;
  heading: string;
  valueOf: (line: PayrollRunLine) => number | string;
  total: number | string;
}

interface RunStatementSheetProps {
  title: string;
  /** The API path, inside the run, of the statement's Excel file. */
  excelPath: string;
  /** The employer's identifier for this statement (SSNIT employer number, TIN), with its label. */
  employerIdentifier: (client: Client) => { label: string; value: string | null };
  /** Text columns after No. and before the figures. */
  textColumns: { key: string; heading: string; valueOf: (line: PayrollRunLine) => string | null }[];
  figureColumns: (run: PayrollRun, lines: PayrollRunLine[]) => StatementColumn[];
}

export function RunStatementSheet({ title, excelPath, employerIdentifier, textColumns, figureColumns }: RunStatementSheetProps) {
  const routeParameters = useParams();
  const clientId = Number(routeParameters.clientId);
  const payrollRunId = Number(routeParameters.payrollRunId);
  const runPath = clientApiPath(clientId, `payroll-runs/${payrollRunId}`);

  const clientsQuery = useQuery({
    queryKey: queryKeys.clients(),
    queryFn: async () => (await api.get<{ clients: Client[] }>("/api/clients")).data.clients,
  });
  const runQuery = useQuery({
    queryKey: queryKeys.payrollRun(clientId, payrollRunId),
    queryFn: async () => (await api.get<{ run: PayrollRun }>(runPath)).data.run,
  });

  if (runQuery.isPending || clientsQuery.isPending) {
    return <LoadingState />;
  }
  if (runQuery.isError) {
    return <EmptyState>{errorMessage(runQuery.error)}</EmptyState>;
  }

  const run = runQuery.data;
  // Statements are filed from frozen figures only: a draft's lines can still change.
  if (run.status === "draft") {
    return <EmptyState>Finalise the payroll run before printing its statements.</EmptyState>;
  }

  const client = clientsQuery.data?.find((candidate) => candidate.client_id === clientId);
  const identifier = client ? employerIdentifier(client) : null;
  const lines = run.lines ?? [];
  const columns = figureColumns(run, lines);

  return (
    <div className="text-[#16202e]">
      <style>{STATEMENT_PRINT_STYLES}</style>

      <div className="screen-only sticky top-0 z-10 flex justify-center gap-2 bg-[#e9edf2] p-3">
        <Button variant="primary" onClick={() => window.print()}>
          Print or save as PDF
        </Button>
        {/* A plain link: the browser downloads the workbook with the session cookie. */}
        <a
          href={`${runPath}/${excelPath}`}
          className="inline-flex items-center justify-center rounded-md border border-rule bg-surface px-3.5 py-2 text-sm font-medium hover:bg-brand-tint"
        >
          Download Excel
        </a>
        <Button onClick={() => window.close()}>Close</Button>
      </div>

      <article className="statement-sheet">
        <header className="flex items-end justify-between gap-6 border-b-2 border-[#16202e] pb-3">
          <div>
            <h1 className="text-[16pt] leading-none font-bold tracking-[0.08em]">{title}</h1>
            {client ? <p className="mt-1.5 font-semibold">{client.client_name}</p> : null}
          </div>
          <dl className="text-right">
            <div className="flex justify-end gap-2.5">
              <dt className="text-[#55606f]">Pay month</dt>
              <dd className="min-w-[34mm] font-semibold">{formatPayMonth(run.pay_period)}</dd>
            </div>
            {identifier?.value ? (
              <div className="flex justify-end gap-2.5">
                <dt className="text-[#55606f]">{identifier.label}</dt>
                <dd className="min-w-[34mm] font-semibold">{identifier.value}</dd>
              </div>
            ) : null}
          </dl>
        </header>

        <table className="mt-5 w-full border-separate border-spacing-0">
          <thead>
            <tr className="text-[7.5pt] text-[#55606f]">
              <th className="border-b border-[#16202e] px-1.5 py-2 text-left font-bold">No.</th>
              {textColumns.map((textColumn) => (
                <th key={textColumn.key} className="border-b border-[#16202e] px-1.5 py-2 text-left font-bold">
                  {textColumn.heading}
                </th>
              ))}
              {columns.map((column) => (
                <th key={column.key} className="figure border-b border-[#16202e] px-1.5 py-2 font-bold whitespace-normal">
                  {column.heading}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {lines.map((line, lineIndex) => (
              <tr key={line.payroll_run_line_id}>
                <td className="border-b border-[#c9cfd8] px-1.5 py-1.5 text-[#55606f]">{lineIndex + 1}</td>
                {textColumns.map((textColumn) => (
                  <td key={textColumn.key} className="border-b border-[#c9cfd8] px-1.5 py-1.5">
                    {textColumn.valueOf(line) || "—"}
                  </td>
                ))}
                {columns.map((column) => (
                  <td key={column.key} className="figure border-b border-[#c9cfd8] px-1.5 py-1.5">
                    {formatMoney(column.valueOf(line))}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="font-bold">
              <td colSpan={textColumns.length + 1} className="border-y-2 border-[#16202e] px-1.5 py-2 text-right">
                Total
              </td>
              {columns.map((column) => (
                <td key={column.key} className="figure border-y-2 border-[#16202e] px-1.5 py-2">
                  {formatMoney(column.total)}
                </td>
              ))}
            </tr>
          </tfoot>
        </table>

        <StatementSignatures />
      </article>
    </div>
  );
}

function StatementSignatures() {
  return (
    <footer className="mt-14 flex justify-between gap-10 text-[9pt] text-[#55606f]">
      <div className="w-[62mm] border-t border-[#16202e] pt-1.5">Prepared by</div>
      <div className="w-[62mm] border-t border-[#16202e] pt-1.5">Authorised signatory</div>
    </footer>
  );
}
