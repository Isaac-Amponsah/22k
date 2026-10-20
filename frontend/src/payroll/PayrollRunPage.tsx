// One month's payroll run: the schedule in the layout of the accountant's payroll sheet, and its actions.

import { useState } from "react";
import type { ReactNode } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage } from "../lib/apiClient";
import { formatDate, formatMoney, formatPayMonth, formatPercent } from "../lib/formatting";
import { itemAmountOf, itemColumnsOf, sumOf, totalOver } from "../lib/payrollLines";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { ActionsMenu } from "../shared/ActionsMenu";
import { Button, ConfirmDialog, EmptyState, LoadingState, Notice, PageHeading, Panel, RunStatusBadge } from "../shared/ui";
import type { PayrollRun, PayrollRunLine, RunBankEmail, RunPayslipEmails } from "../types";
import { SendToBankDialog } from "./SendToBankDialog";

type RunAction = "recalculate" | "finalise" | "mark-paid" | "delete";

const CONFIRMATIONS: Record<Exclude<RunAction, "recalculate">, { title: string; explanation: string; confirmLabel: string }> = {
  finalise: {
    title: "Finalise this payroll run?",
    explanation: "The figures can no longer be changed.",
    confirmLabel: "Finalise run",
  },
  "mark-paid": {
    title: "Mark this run paid?",
    explanation: "This cannot be undone.",
    confirmLabel: "Mark paid",
  },
  delete: {
    title: "Delete this draft?",
    explanation: "This cannot be undone.",
    confirmLabel: "Delete draft",
  },
};

interface FigureColumn {
  key: string;
  heading: ReactNode;
  valueOf: (line: PayrollRunLine) => number | string;
  total: number | string;
  tone?: "plain" | "strong" | "soft";
}

/** A line figure the run also keeps a total of. */
type RunFigure = {
  [Figure in keyof PayrollRunLine]: `total_${Figure}` extends keyof PayrollRun ? Figure : never;
}[keyof PayrollRunLine];

const FIGURE_TONE_CLASSES: Record<NonNullable<FigureColumn["tone"]>, string> = {
  plain: "",
  strong: "font-semibold",
  soft: "text-ink-soft",
};

/**
 * The schedule's figure columns, in the order of the accountant's payroll sheet: earnings, then what is taken
 * before and after PAYE. Tier 3, tax relief and deductions show only when the run has any.
 */
function figureColumnsOf(run: PayrollRun, lines: PayrollRunLine[]): FigureColumn[] {
  const figure = (key: RunFigure, heading: ReactNode, tone?: FigureColumn["tone"]): FigureColumn => ({
    key,
    heading,
    valueOf: (line) => line[key],
    total: run[`total_${key}`],
    tone,
  });
  const itemColumns = (itemKind: "allowance" | "deduction") =>
    itemColumnsOf(lines, itemKind).map((itemColumn) => ({
      key: `${itemKind}:${itemColumn.itemName}`,
      heading: (
        <>
          {itemColumn.itemName}
          {itemKind === "allowance" && !itemColumn.isTaxable ? <span className="block text-xs font-normal text-success">Non-taxable</span> : null}
        </>
      ),
      valueOf: (line: PayrollRunLine) => itemAmountOf(line, itemKind, itemColumn.itemName),
      total: totalOver(lines, (line) => itemAmountOf(line, itemKind, itemColumn.itemName)),
    }));

  const allowanceColumns = itemColumns("allowance");
  return [
    figure("basic_salary", "Basic salary"),
    figure("employee_ssnit", `SSNIT (${formatPercent(run.employee_ssnit_percent)}%)`),
    figure("basic_less_ssnit", "Basic less SSNIT"),
    ...(allowanceColumns.length > 0 ? allowanceColumns : [figure("allowance", "Allowance")]),
    ...(Number(run.total_tier3_contribution) > 0 ? [figure("tier3_contribution", "Tier 3")] : []),
    ...(sumOf(run.total_tier3_relief, run.total_personal_relief) > 0
      ? [
          {
            key: "tax_relief",
            heading: "Tax relief",
            valueOf: (line: PayrollRunLine) => sumOf(line.tier3_relief, line.personal_relief),
            total: sumOf(run.total_tier3_relief, run.total_personal_relief),
          },
        ]
      : []),
    figure("chargeable_income", "Chargeable income"),
    figure("paye", "PAYE"),
    ...itemColumns("deduction"),
    figure("net_pay", "Take home", "strong"),
    figure("employer_ssnit", `Employer SSNIT (${formatPercent(run.employer_ssnit_percent)}%)`, "soft"),
  ];
}

const LINK_BUTTON_CLASSES =
  "inline-flex items-center justify-center rounded-md border border-rule bg-surface px-3.5 py-2 text-sm font-medium hover:bg-brand-tint";

export function PayrollRunPage() {
  const client = useClientInScope();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const payrollRunId = Number(useParams().payrollRunId);
  const runPath = clientApiPath(client.client_id, `payroll-runs/${payrollRunId}`);

  const [pendingConfirmation, setPendingConfirmation] = useState<Exclude<RunAction, "recalculate"> | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "refusal"; text: string } | null>(null);
  const [isSendToBankOpen, setIsSendToBankOpen] = useState(false);

  const runQuery = useQuery({
    queryKey: queryKeys.payrollRun(client.client_id, payrollRunId),
    queryFn: async () => (await api.get<{ run: PayrollRun }>(runPath)).data.run,
  });

  // A draft is never sent to the bank, so there is nothing to fetch for one.
  const bankEmailQuery = useQuery({
    queryKey: queryKeys.runBankEmail(client.client_id, payrollRunId),
    queryFn: async () => (await api.get<{ bank_email: RunBankEmail }>(`${runPath}/bank-email`)).data.bank_email,
    enabled: runQuery.data !== undefined && runQuery.data.status !== "draft",
  });

  const payslipEmailsQuery = useQuery({
    queryKey: queryKeys.runPayslipEmails(client.client_id, payrollRunId),
    queryFn: async () => (await api.get<{ payslip_emails: RunPayslipEmails }>(`${runPath}/payslip-emails`)).data.payslip_emails,
    enabled: runQuery.data !== undefined && runQuery.data.status !== "draft",
  });

  const emailPayslipsMutation = useMutation({
    mutationFn: () => api.post<{ payslip_emails: RunPayslipEmails }>(`${runPath}/payslip-emails`),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.runPayslipEmails(client.client_id, payrollRunId) });
      setNotice({ tone: "success", text: result.message });
    },
    onError: (thrown) => setNotice({ tone: "refusal", text: errorMessage(thrown) }),
  });

  const actionMutation = useMutation({
    mutationFn: (action: RunAction) => (action === "delete" ? api.delete(runPath) : api.post(`${runPath}/${action}`)),
    onSuccess: async (result, action) => {
      if (action === "delete") {
        queryClient.removeQueries({ queryKey: queryKeys.payrollRun(client.client_id, payrollRunId) });
        navigate("..", { relative: "path" });
        return;
      }
      await queryClient.invalidateQueries({ queryKey: queryKeys.payrollRuns(client.client_id) });
      setNotice({ tone: "success", text: result.message });
    },
    onError: (thrown) => setNotice({ tone: "refusal", text: errorMessage(thrown) }),
    onSettled: () => setPendingConfirmation(null),
  });

  if (runQuery.isPending) {
    return <LoadingState />;
  }
  if (runQuery.isError) {
    return (
      <EmptyState>
        {errorMessage(runQuery.error)}{" "}
        <Link to=".." relative="path" className="font-medium text-brand underline">
          Back to payroll
        </Link>
      </EmptyState>
    );
  }

  const run = runQuery.data;
  const lines = run.lines ?? [];
  const figureColumns = figureColumnsOf(run, lines);
  const isDraft = run.status === "draft";
  const canChange = !client.is_archived && !actionMutation.isPending;
  // Latest first.
  const lastSentBankEmail = bankEmailQuery.data?.sent_emails[0];
  const payslipEmails = payslipEmailsQuery.data;
  const emailedPayslipCount = payslipEmails?.recipients.filter((recipient) => recipient.sent_to_email !== null).length ?? 0;

  return (
    <>
      <PageHeading
        title={`Payroll for ${formatPayMonth(run.pay_period)}`}
        description={run.notes ?? undefined}
        actions={
          <Link to=".." relative="path" className={LINK_BUTTON_CLASSES}>
            All runs
          </Link>
        }
      />

      {notice ? (
        <div className="mb-4">
          <Notice tone={notice.tone}>{notice.text}</Notice>
        </div>
      ) : null}

      <div className="grid gap-6 xl:grid-cols-[1fr_16rem]">
        <Panel>
          <div className="flex items-center justify-between border-b border-rule px-4 py-3">
            <RunStatusBadge status={run.status} />
            <span className="text-sm text-ink-soft">Amounts in GHS</span>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-rule text-ink-soft">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">No.</th>
                  <th className="px-2 py-3 text-left font-medium">Name</th>
                  {figureColumns.map((figureColumn) => (
                    <th key={figureColumn.key} className="figure px-3 py-3 font-medium">
                      {figureColumn.heading}
                    </th>
                  ))}
                  {isDraft ? null : <th className="screen-only px-3 py-3" />}
                </tr>
              </thead>
              <tbody>
                {lines.length === 0 ? (
                  <tr>
                    <td colSpan={figureColumns.length + 3}>
                      <EmptyState>Nobody is paid by this run. Set salaries, then recalculate.</EmptyState>
                    </td>
                  </tr>
                ) : (
                  lines.map((line, lineIndex) => (
                    <tr key={line.payroll_run_line_id} className="border-b border-rule/60">
                      <td className="px-4 py-2.5 text-ink-soft">{lineIndex + 1}</td>
                      <td className="px-2 py-2.5">
                        <span className="font-medium">{line.employee_name}</span>
                        {line.job_title_name ? <span className="block text-ink-soft">{line.job_title_name}</span> : null}
                      </td>
                      {figureColumns.map((figureColumn) => (
                        <td key={figureColumn.key} className={`figure px-3 py-2.5 ${FIGURE_TONE_CLASSES[figureColumn.tone ?? "plain"]}`}>
                          {formatMoney(figureColumn.valueOf(line))}
                        </td>
                      ))}
                      {isDraft ? null : (
                        <td className="screen-only px-3 py-1.5 text-right">
                          <ActionsMenu
                            label={`Actions for ${line.employee_name}`}
                            actions={[
                              { label: "Open payslip", to: `payslips?line=${line.payroll_run_line_id}`, opensInNewTab: true },
                              // A plain link: the browser downloads the PDF with the session cookie.
                              { label: "Download PDF", href: `${runPath}/payslips/${line.payroll_run_line_id}/pdf` },
                              { label: "Employee's payslips", to: `/clients/${client.client_id}/employees/${line.employee_id}/payslips` },
                            ]}
                          />
                        </td>
                      )}
                    </tr>
                  ))
                )}
              </tbody>
              <tfoot className="border-t-2 border-ink font-semibold">
                <tr>
                  <td colSpan={2} className="px-4 py-3 text-right">
                    Total
                  </td>
                  {figureColumns.map((figureColumn) => (
                    <td key={figureColumn.key} className="figure px-3 py-3">
                      {formatMoney(figureColumn.total)}
                    </td>
                  ))}
                  {isDraft ? null : <td className="screen-only" />}
                </tr>
              </tfoot>
            </table>
          </div>
        </Panel>

        <aside className="screen-only space-y-6">
          <Panel className="grid gap-2 p-4">
            {isDraft ? (
              <>
                <Button variant="primary" disabled={!canChange || lines.length === 0} onClick={() => setPendingConfirmation("finalise")}>
                  Finalise
                </Button>
                <Button disabled={!canChange} onClick={() => actionMutation.mutate("recalculate")}>
                  Recalculate
                </Button>
                <Link to="../../salaries" relative="path" className={LINK_BUTTON_CLASSES}>
                  Edit salaries
                </Link>
                <Button variant="danger" disabled={!canChange} onClick={() => setPendingConfirmation("delete")}>
                  Delete draft
                </Button>
              </>
            ) : null}
            {run.status === "finalised" ? (
              <Button variant="primary" disabled={!canChange} onClick={() => setPendingConfirmation("mark-paid")}>
                Mark paid
              </Button>
            ) : null}
            {isDraft ? null : (
              <>
                <Button disabled={!canChange} onClick={() => setIsSendToBankOpen(true)}>
                  Send to bank
                </Button>
                <a href={`${runPath}/bank-file`} className={LINK_BUTTON_CLASSES}>
                  Download bank file
                </a>
                <Link to="payslips" target="_blank" className={LINK_BUTTON_CLASSES}>
                  Print all payslips
                </Link>
                {payslipEmails && payslipEmails.unsent_count > 0 ? (
                  <Button
                    disabled={!canChange || !payslipEmails.is_mail_configured || emailPayslipsMutation.isPending}
                    onClick={() => emailPayslipsMutation.mutate()}
                  >
                    {emailPayslipsMutation.isPending ? "Emailing payslips…" : `Email ${payslipEmails.unsent_count} unsent payslips`}
                  </Button>
                ) : null}
                <Link to="contributions" target="_blank" className={LINK_BUTTON_CLASSES}>
                  SSNIT contribution statement
                </Link>
                <Link to="tax-analysis" target="_blank" className={LINK_BUTTON_CLASSES}>
                  Tax analysis
                </Link>
              </>
            )}
            {/* A plain link: the browser downloads the workbook with the session cookie. */}
            <a href={`${runPath}/export`} className={LINK_BUTTON_CLASSES}>
              Download Excel
            </a>
          </Panel>

          <Panel className="p-4 text-sm">
            <dl className="space-y-3">
              <div>
                <dt className="text-ink-soft">Created by</dt>
                <dd>{run.created_by_name || "—"}</dd>
              </div>
              {run.finalised_at ? (
                <div>
                  <dt className="text-ink-soft">Finalised</dt>
                  <dd>
                    {formatDate(run.finalised_at)}
                    {run.finalised_by_name ? ` by ${run.finalised_by_name}` : ""}
                  </dd>
                </div>
              ) : null}
              {run.paid_at ? (
                <div>
                  <dt className="text-ink-soft">Paid</dt>
                  <dd>
                    {formatDate(run.paid_at)}
                    {run.paid_by_name ? ` by ${run.paid_by_name}` : ""}
                  </dd>
                </div>
              ) : null}
              {payslipEmails && emailedPayslipCount > 0 ? (
                <div>
                  <dt className="text-ink-soft">Payslips emailed</dt>
                  <dd>
                    {emailedPayslipCount} of {payslipEmails.recipients.length}
                  </dd>
                  {payslipEmails.employees_missing_email.length > 0 ? (
                    <dd className="text-ink-soft">No email address: {payslipEmails.employees_missing_email.join(", ")}</dd>
                  ) : null}
                </div>
              ) : null}
              {lastSentBankEmail ? (
                <div>
                  <dt className="text-ink-soft">Sent to bank</dt>
                  <dd>
                    {formatDate(lastSentBankEmail.sent_at)}
                    {lastSentBankEmail.sent_by_name ? ` by ${lastSentBankEmail.sent_by_name}` : ""}
                  </dd>
                  <dd className="break-words text-ink-soft">{lastSentBankEmail.recipient_emails.join(", ")}</dd>
                </div>
              ) : null}
            </dl>
          </Panel>
        </aside>
      </div>

      <ConfirmDialog
        isOpen={pendingConfirmation !== null}
        title={pendingConfirmation ? CONFIRMATIONS[pendingConfirmation].title : ""}
        explanation={pendingConfirmation ? CONFIRMATIONS[pendingConfirmation].explanation : ""}
        confirmLabel={pendingConfirmation ? CONFIRMATIONS[pendingConfirmation].confirmLabel : ""}
        isDangerous={pendingConfirmation === "delete"}
        isWorking={actionMutation.isPending}
        onConfirm={() => pendingConfirmation && actionMutation.mutate(pendingConfirmation)}
        onCancel={() => setPendingConfirmation(null)}
      />

      <SendToBankDialog
        isOpen={isSendToBankOpen}
        clientId={client.client_id}
        payrollRunId={payrollRunId}
        bankEmail={bankEmailQuery.data}
        payslipEmails={payslipEmails}
        onClose={() => setIsSendToBankOpen(false)}
        onSent={(message) => {
          setIsSendToBankOpen(false);
          void queryClient.invalidateQueries({ queryKey: queryKeys.runPayslipEmails(client.client_id, payrollRunId) });
          setNotice({ tone: "success", text: message });
        }}
      />
    </>
  );
}
