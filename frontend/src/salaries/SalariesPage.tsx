// Salaries: one form for every active employee of the client whose books are open. Each row shows the
// amount field its allowance type uses and works its pay out again as the figures are typed.

import { useMemo, useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { formatMoney, formatPercent, fullName } from "../lib/formatting";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { previewSalary } from "../lib/salaryPreview";
import { Button, EmptyState, INPUT_CLASSES, LoadingState, Notice, PageHeading, Panel } from "../shared/ui";
import type { AllowanceMode, RateSet, SalariedEmployee } from "../types";

interface SalaryListAnswer {
  employees: SalariedEmployee[];
  rate_set: RateSet | null;
}

interface SalaryRowInput {
  basic_salary: string;
  allowance_mode: AllowanceMode;
  flat_allowance: string;
  target_chargeable_income: string;
}

/** "4000.00" → "4000.00"; null → "". */
function amountInputValue(storedAmount: string | null): string {
  return storedAmount === null ? "" : Number(storedAmount).toFixed(2);
}

function rowInputOf(employee: SalariedEmployee): SalaryRowInput {
  return {
    basic_salary: amountInputValue(employee.basic_salary),
    allowance_mode: employee.allowance_mode ?? "flat",
    flat_allowance: amountInputValue(employee.flat_allowance),
    target_chargeable_income: amountInputValue(employee.target_chargeable_income),
  };
}

export function SalariesPage() {
  const client = useClientInScope();

  const salariesQuery = useQuery({
    queryKey: queryKeys.salaries(client.client_id),
    queryFn: async () => (await api.get<SalaryListAnswer>(clientApiPath(client.client_id, "salaries"))).data,
  });

  return (
    <>
      <PageHeading
        title="Salaries"
        actions={
          client.is_archived ? null : (
            <Link
              to="import"
              className="inline-flex items-center rounded-md border border-rule bg-surface px-3.5 py-2 text-sm font-medium hover:bg-brand-tint"
            >
              Import from Excel
            </Link>
          )
        }
      />

      {salariesQuery.isError ? <Notice tone="refusal">{errorMessage(salariesQuery.error)}</Notice> : null}
      {salariesQuery.isPending ? <LoadingState /> : null}
      {salariesQuery.isSuccess ? (
        // Keyed by when the list was fetched: after a save the form starts again from what was stored.
        <SalariesForm key={salariesQuery.dataUpdatedAt} salaryList={salariesQuery.data} />
      ) : null}
    </>
  );
}

function SalariesForm({ salaryList }: { salaryList: SalaryListAnswer }) {
  const client = useClientInScope();
  const queryClient = useQueryClient();
  const { employees, rate_set: rateSet } = salaryList;
  const canEdit = !client.is_archived && rateSet !== null;

  const [rowInputs, setRowInputs] = useState<Record<number, SalaryRowInput>>(() =>
    Object.fromEntries(employees.map((employee) => [employee.employee_id, rowInputOf(employee)])),
  );
  const [successMessage, setSuccessMessage] = useState("");

  const saveMutation = useMutation({
    mutationFn: () => api.put<{ saved: number }>(clientApiPath(client.client_id, "salaries"), { salaries: rowInputs }),
    onSuccess: (result) => {
      setSuccessMessage(result.message);
      return queryClient.invalidateQueries({ queryKey: queryKeys.salaries(client.client_id) });
    },
    onMutate: () => setSuccessMessage(""),
  });

  const serverFieldErrors = fieldErrorsOf(saveMutation.error);
  const withoutSalaryCount = useMemo(() => employees.filter((employee) => employee.employee_salary_id === null).length, [employees]);

  function changeRow(employeeId: number, changedInput: Partial<SalaryRowInput>) {
    setRowInputs((currentInputs) => ({ ...currentInputs, [employeeId]: { ...currentInputs[employeeId], ...changedInput } }));
  }

  /** The typed figure stays as it is; what it means changes with the type, so the worked-out columns move. */
  function changeAllowanceMode(employeeId: number, allowanceMode: AllowanceMode) {
    const currentInput = rowInputs[employeeId];
    changeRow(
      employeeId,
      allowanceMode === "target"
        ? { allowance_mode: allowanceMode, target_chargeable_income: currentInput.flat_allowance }
        : { allowance_mode: allowanceMode, flat_allowance: currentInput.target_chargeable_income },
    );
  }

  function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    saveMutation.mutate();
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {rateSet === null ? (
        <Notice tone="attention">
          Payroll rates have not been set up yet, so salaries cannot be saved.{" "}
          <Link to="/payroll-rates" className="font-medium underline">
            Add a rate set
          </Link>
        </Notice>
      ) : null}
      {successMessage ? <Notice tone="success">{successMessage}</Notice> : null}
      {saveMutation.isError ? <Notice tone="refusal">{errorMessage(saveMutation.error)}</Notice> : null}

      <Panel>
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-4 py-3">
          <p className="text-sm text-ink-soft">
            {employees.length} active {employees.length === 1 ? "employee" : "employees"},{" "}
            <span className={withoutSalaryCount > 0 ? "font-medium text-refusal" : ""}>{withoutSalaryCount} without a salary</span>
            {rateSet ? `. Employee SSNIT ${formatPercent(rateSet.employee_ssnit_percent)}%.` : "."}
          </p>
          {canEdit && employees.length > 0 ? (
            <Button type="submit" variant="primary" disabled={saveMutation.isPending}>
              {saveMutation.isPending ? "Saving…" : "Save salaries"}
            </Button>
          ) : null}
        </div>

        {employees.length === 0 ? (
          <EmptyState>
            No active employees.{" "}
            <Link to="../employees" className="font-medium text-brand underline">
              Add employees
            </Link>{" "}
            or import a payroll sheet.
          </EmptyState>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-rule text-left text-ink-soft">
                <tr>
                  <th className="px-4 py-3 font-medium">Employee</th>
                  <th className="px-2 py-3 font-medium">Basic salary (GHS)</th>
                  <th className="px-2 py-3 font-medium">Allowance type</th>
                  <th className="px-2 py-3 font-medium">Allowance or target (GHS)</th>
                  <th className="figure px-3 py-3 font-medium">Allowance</th>
                  <th className="figure px-3 py-3 font-medium">Chargeable</th>
                  <th className="figure px-3 py-3 font-medium">PAYE</th>
                  <th className="figure px-4 py-3 font-medium">Take home</th>
                </tr>
              </thead>
              <tbody>
                {employees.map((employee) => {
                  const rowInput = rowInputs[employee.employee_id];
                  const isTargetMode = rowInput.allowance_mode === "target";
                  const preview = rateSet
                    ? previewSalary(
                        rowInput.basic_salary,
                        rowInput.allowance_mode,
                        rowInput.flat_allowance,
                        rowInput.target_chargeable_income,
                        rateSet,
                      )
                    : null;
                  const fieldPrefix = `salaries[${employee.employee_id}]`;
                  const rowError =
                    serverFieldErrors[`${fieldPrefix}[basic_salary]`] ??
                    serverFieldErrors[`${fieldPrefix}[allowance_mode]`] ??
                    serverFieldErrors[`${fieldPrefix}[flat_allowance]`] ??
                    serverFieldErrors[`${fieldPrefix}[target_chargeable_income]`] ??
                    preview?.error ??
                    null;
                  const figures = preview?.figures ?? null;

                  return (
                    <tr key={employee.employee_id} className="border-b border-rule/60 align-top last:border-0">
                      <td className="px-4 py-3">
                        <span className="font-medium">{fullName(employee)}</span>
                        {employee.job_title_name ? <span className="block text-ink-soft">{employee.job_title_name}</span> : null}
                      </td>
                      <td className="px-2 py-2">
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          inputMode="decimal"
                          aria-label={`Basic salary for ${fullName(employee)}`}
                          className={`${INPUT_CLASSES} w-32`}
                          disabled={!canEdit}
                          value={rowInput.basic_salary}
                          onChange={(changeEvent) => changeRow(employee.employee_id, { basic_salary: changeEvent.target.value })}
                        />
                      </td>
                      <td className="px-2 py-2">
                        <select
                          aria-label={`Allowance type for ${fullName(employee)}`}
                          className={`${INPUT_CLASSES} w-56`}
                          disabled={!canEdit}
                          value={rowInput.allowance_mode}
                          onChange={(changeEvent) => changeAllowanceMode(employee.employee_id, changeEvent.target.value as AllowanceMode)}
                        >
                          <option value="flat">Flat amount</option>
                          <option value="target">Top up to chargeable income of</option>
                        </select>
                      </td>
                      <td className="px-2 py-2">
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          inputMode="decimal"
                          aria-label={`${isTargetMode ? "Target chargeable income" : "Flat allowance"} for ${fullName(employee)}`}
                          className={`${INPUT_CLASSES} w-32`}
                          disabled={!canEdit}
                          value={isTargetMode ? rowInput.target_chargeable_income : rowInput.flat_allowance}
                          onChange={(changeEvent) =>
                            changeRow(
                              employee.employee_id,
                              isTargetMode
                                ? { target_chargeable_income: changeEvent.target.value }
                                : { flat_allowance: changeEvent.target.value },
                            )
                          }
                        />
                        {rowError ? <p className="mt-1 max-w-56 text-sm text-refusal">{rowError}</p> : null}
                      </td>
                      <td className="figure px-3 py-3">{formatMoney(figures?.allowance)}</td>
                      <td className="figure px-3 py-3">{formatMoney(figures?.chargeable_income)}</td>
                      <td className="figure px-3 py-3">{formatMoney(figures?.paye)}</td>
                      <td className="figure px-4 py-3 font-semibold">{formatMoney(figures?.net_pay)}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </form>
  );
}
