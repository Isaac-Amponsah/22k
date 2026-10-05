// The monthly payroll runs of the client whose books are open, and the form that starts a new one.

import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage } from "../lib/apiClient";
import { currentMonthInputValue, formatMoney, formatPayMonth } from "../lib/formatting";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { Button, EmptyState, LoadingState, Notice, PageHeading, Panel, RunStatusBadge, TextField } from "../shared/ui";
import type { PayrollRun } from "../types";

interface PayrollRunsAnswer {
  runs: PayrollRun[];
  latest_month: string;
}

export function PayrollRunsPage() {
  const client = useClientInScope();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [payMonth, setPayMonth] = useState(currentMonthInputValue);
  const [notes, setNotes] = useState("");

  const runsQuery = useQuery({
    queryKey: queryKeys.payrollRuns(client.client_id),
    queryFn: async () => (await api.get<PayrollRunsAnswer>(clientApiPath(client.client_id, "payroll-runs"))).data,
  });

  const createMutation = useMutation({
    mutationFn: () =>
      api.post<{ payroll_run_id: number }>(clientApiPath(client.client_id, "payroll-runs"), { pay_month: payMonth, notes }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.payrollRuns(client.client_id) });
      navigate(String(result.data.payroll_run_id));
    },
  });

  function handleCreate(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    createMutation.mutate();
  }

  const runs = runsQuery.data?.runs ?? [];

  return (
    <>
      <PageHeading title="Payroll" />

      <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <Panel>
          {runsQuery.isError ? (
            <div className="p-4">
              <Notice tone="refusal">{errorMessage(runsQuery.error)}</Notice>
            </div>
          ) : runsQuery.isPending ? (
            <LoadingState />
          ) : runs.length === 0 ? (
            <EmptyState>No payroll runs yet. Set salaries, then start the first month's run.</EmptyState>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-rule text-left text-ink-soft">
                  <tr>
                    <th className="px-4 py-3 font-medium">Month</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="figure px-4 py-3 font-medium">Employees</th>
                    <th className="figure px-4 py-3 font-medium">PAYE</th>
                    <th className="figure px-4 py-3 font-medium">Take home</th>
                  </tr>
                </thead>
                <tbody>
                  {runs.map((run) => (
                    <tr key={run.payroll_run_id} className="border-b border-rule/60 last:border-0">
                      <td className="px-4 py-3">
                        <Link to={String(run.payroll_run_id)} className="font-medium text-brand underline underline-offset-2">
                          {formatPayMonth(run.pay_period)}
                        </Link>
                      </td>
                      <td className="px-4 py-3">
                        <RunStatusBadge status={run.status} />
                      </td>
                      <td className="figure px-4 py-3">{run.employee_count}</td>
                      <td className="figure px-4 py-3">{formatMoney(run.total_paye)}</td>
                      <td className="figure px-4 py-3 font-semibold">{formatMoney(run.total_net_pay)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </Panel>

        {client.is_archived ? null : (
          <Panel className="h-fit p-5">
            <h2 className="mb-4 font-serif text-lg font-semibold">Start a month's run</h2>
            <form onSubmit={handleCreate} className="space-y-4">
              {createMutation.isError ? <Notice tone="refusal">{errorMessage(createMutation.error)}</Notice> : null}
              <TextField
                label="Month to pay"
                name="pay_month"
                type="month"
                required
                max={runsQuery.data?.latest_month}
                value={payMonth}
                onChange={(changeEvent) => setPayMonth(changeEvent.target.value)}
              />
              <TextField
                label="Note (optional)"
                name="notes"
                maxLength={500}
                value={notes}
                onChange={(changeEvent) => setNotes(changeEvent.target.value)}
              />
              <Button type="submit" variant="primary" className="w-full" disabled={createMutation.isPending}>
                {createMutation.isPending ? "Working out pay…" : "Create draft run"}
              </Button>
            </form>
          </Panel>
        )}
      </div>
    </>
  );
}
