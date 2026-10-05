// One month's payroll run: the schedule in the layout of the accountant's payroll sheet, and its actions.

import { useState } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate, useParams } from "react-router-dom";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage } from "../lib/apiClient";
import { formatDate, formatMoney, formatPayMonth, formatPercent } from "../lib/formatting";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { Button, ConfirmDialog, EmptyState, LoadingState, Notice, PageHeading, Panel, RunStatusBadge } from "../shared/ui";
import type { PayrollRun } from "../types";

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

const LINK_BUTTON_CLASSES =
  "inline-flex items-center justify-center rounded-md border border-rule bg-surface px-3.5 py-2 text-sm font-medium hover:bg-ledger-tint";

export function PayrollRunPage() {
  const client = useClientInScope();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const payrollRunId = Number(useParams().payrollRunId);
  const runPath = clientApiPath(client.client_id, `payroll-runs/${payrollRunId}`);

  const [pendingConfirmation, setPendingConfirmation] = useState<Exclude<RunAction, "recalculate"> | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "refusal"; text: string } | null>(null);

  const runQuery = useQuery({
    queryKey: queryKeys.payrollRun(client.client_id, payrollRunId),
    queryFn: async () => (await api.get<{ run: PayrollRun }>(runPath)).data.run,
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
        <Link to=".." relative="path" className="font-medium text-ledger underline">
          Back to payroll
        </Link>
      </EmptyState>
    );
  }

  const run = runQuery.data;
  const lines = run.lines ?? [];
  const isDraft = run.status === "draft";
  const canChange = !client.is_archived && !actionMutation.isPending;

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
                  <th className="figure px-3 py-3 font-medium">Basic salary</th>
                  <th className="figure px-3 py-3 font-medium">SSNIT ({formatPercent(run.employee_ssnit_percent)}%)</th>
                  <th className="figure px-3 py-3 font-medium">Basic less SSNIT</th>
                  <th className="figure px-3 py-3 font-medium">Allowance</th>
                  <th className="figure px-3 py-3 font-medium">Chargeable income</th>
                  <th className="figure px-3 py-3 font-medium">PAYE</th>
                  <th className="figure px-3 py-3 font-medium">Take home</th>
                  <th className="figure px-3 py-3 font-medium">Employer SSNIT ({formatPercent(run.employer_ssnit_percent)}%)</th>
                  {isDraft ? null : <th className="screen-only px-3 py-3" />}
                </tr>
              </thead>
              <tbody>
                {lines.length === 0 ? (
                  <tr>
                    <td colSpan={11}>
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
                      <td className="figure px-3 py-2.5">{formatMoney(line.basic_salary)}</td>
                      <td className="figure px-3 py-2.5">{formatMoney(line.employee_ssnit)}</td>
                      <td className="figure px-3 py-2.5">{formatMoney(line.basic_less_ssnit)}</td>
                      <td className="figure px-3 py-2.5">{formatMoney(line.allowance)}</td>
                      <td className="figure px-3 py-2.5">{formatMoney(line.chargeable_income)}</td>
                      <td className="figure px-3 py-2.5">{formatMoney(line.paye)}</td>
                      <td className="figure px-3 py-2.5 font-semibold">{formatMoney(line.net_pay)}</td>
                      <td className="figure px-3 py-2.5 text-ink-soft">{formatMoney(line.employer_ssnit)}</td>
                      {isDraft ? null : (
                        <td className="screen-only px-3 py-2.5 text-right">
                          <Link
                            to={`payslips?line=${line.payroll_run_line_id}`}
                            target="_blank"
                            className="text-ledger underline underline-offset-2"
                          >
                            Payslip
                          </Link>
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
                  <td className="figure px-3 py-3">{formatMoney(run.total_basic_salary)}</td>
                  <td className="figure px-3 py-3">{formatMoney(run.total_employee_ssnit)}</td>
                  <td className="figure px-3 py-3">{formatMoney(run.total_basic_less_ssnit)}</td>
                  <td className="figure px-3 py-3">{formatMoney(run.total_allowance)}</td>
                  <td className="figure px-3 py-3">{formatMoney(run.total_chargeable_income)}</td>
                  <td className="figure px-3 py-3">{formatMoney(run.total_paye)}</td>
                  <td className="figure px-3 py-3">{formatMoney(run.total_net_pay)}</td>
                  <td className="figure px-3 py-3">{formatMoney(run.total_employer_ssnit)}</td>
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
              <Link to="payslips" target="_blank" className={LINK_BUTTON_CLASSES}>
                Print all payslips
              </Link>
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
    </>
  );
}
