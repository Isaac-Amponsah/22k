// Salaries: one form for every active employee of the client whose books are open. Each row works its
// pay out again as the figures are typed.

import { useMemo, useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { formatMoney, formatPercent, fullName } from "../lib/formatting";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { allowanceForTakeHome, basicSalaryForTakeHome, previewSalary, readAmount } from "../lib/salaryPreview";
import type { TakeHomeDerivation } from "../lib/salaryPreview";
import { Button, EmptyState, INPUT_CLASSES, LoadingState, Notice, PageHeading, Panel } from "../shared/ui";
import type { RateSet, SalariedEmployee } from "../types";

interface SalaryListAnswer {
  employees: SalariedEmployee[];
  rate_set: RateSet | null;
}

/** Derived: take home follows basic salary and allowance. Fixed: they follow the take home typed. */
type TakeHomeMode = "derived" | "fixed";

interface SalaryRowInput {
  basic_salary: string;
  flat_allowance: string;
}

/** "4000.00" → "4000.00"; null → "". */
function amountInputValue(storedAmount: string | null): string {
  return storedAmount === null ? "" : Number(storedAmount).toFixed(2);
}

function rowInputOf(employee: SalariedEmployee): SalaryRowInput {
  return {
    basic_salary: amountInputValue(employee.basic_salary),
    flat_allowance: amountInputValue(employee.flat_allowance),
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
  // How each row is entered, and the take home a fixed row holds to. Kept on this page only, never saved.
  const [takeHomeModes, setTakeHomeModes] = useState<Record<number, TakeHomeMode>>({});
  const [fixedTakeHomes, setFixedTakeHomes] = useState<Record<number, string>>({});
  const [derivationErrors, setDerivationErrors] = useState<Record<number, string | null>>({});
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

  function isFixedTakeHome(employeeId: number): boolean {
    return takeHomeModes[employeeId] === "fixed";
  }

  /**
   * Applies a derivation to one figure of a row: the derived amount on success, the refusal otherwise
   * (the figure is then left as it was). A blank take home derives nothing.
   */
  function applyDerivation(
    employeeId: number,
    takeHomeInput: string,
    derivedField: keyof SalaryRowInput,
    derive: (rateSetInForce: RateSet) => TakeHomeDerivation,
  ) {
    if (rateSet === null || takeHomeInput.trim() === "") {
      setDerivationErrors((currentErrors) => ({ ...currentErrors, [employeeId]: null }));
      return;
    }
    const derivation = derive(rateSet);
    if (derivation.amount !== null) {
      changeRow(employeeId, { [derivedField]: derivation.amount.toFixed(2) });
    }
    setDerivationErrors((currentErrors) => ({ ...currentErrors, [employeeId]: derivation.error }));
  }

  function changeBasicSalary(employeeId: number, typedBasicSalary: string) {
    changeRow(employeeId, { basic_salary: typedBasicSalary });
    // A blank basic salary means the row is not saved at all, so there is no allowance to work out.
    if (isFixedTakeHome(employeeId) && typedBasicSalary.trim() !== "") {
      const fixedTakeHome = fixedTakeHomes[employeeId] ?? "";
      applyDerivation(employeeId, fixedTakeHome, "flat_allowance", (rateSetInForce) =>
        allowanceForTakeHome(fixedTakeHome, typedBasicSalary, rateSetInForce),
      );
    }
  }

  function changeAllowance(employeeId: number, typedAllowance: string) {
    changeRow(employeeId, { flat_allowance: typedAllowance });
    if (isFixedTakeHome(employeeId)) {
      const fixedTakeHome = fixedTakeHomes[employeeId] ?? "";
      applyDerivation(employeeId, fixedTakeHome, "basic_salary", (rateSetInForce) =>
        basicSalaryForTakeHome(fixedTakeHome, typedAllowance, rateSetInForce),
      );
    }
  }

  function changeFixedTakeHome(employeeId: number, typedTakeHome: string) {
    setFixedTakeHomes((currentTakeHomes) => ({ ...currentTakeHomes, [employeeId]: typedTakeHome }));
    applyDerivation(employeeId, typedTakeHome, "basic_salary", (rateSetInForce) =>
      basicSalaryForTakeHome(typedTakeHome, rowInputs[employeeId].flat_allowance, rateSetInForce),
    );
  }

  /** A row turning fixed holds to the take home it shows now. */
  function changeTakeHomeMode(employeeId: number, takeHomeMode: TakeHomeMode, currentNetPay: number | null) {
    setTakeHomeModes((currentModes) => ({ ...currentModes, [employeeId]: takeHomeMode }));
    setDerivationErrors((currentErrors) => ({ ...currentErrors, [employeeId]: null }));
    if (takeHomeMode === "fixed") {
      setFixedTakeHomes((currentTakeHomes) => ({
        ...currentTakeHomes,
        [employeeId]: currentNetPay === null ? "" : currentNetPay.toFixed(2),
      }));
    }
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
                  <th className="px-2 py-3 font-medium">Allowance (GHS)</th>
                  <th className="figure px-3 py-3 font-medium">Gross</th>
                  <th className="figure px-3 py-3 font-medium">
                    Employee SSNIT{rateSet ? ` ${formatPercent(rateSet.employee_ssnit_percent)}%` : ""}
                  </th>
                  <th className="figure px-3 py-3 font-medium">Chargeable</th>
                  <th className="figure px-3 py-3 font-medium">PAYE</th>
                  <th className="px-2 py-3 font-medium">Take home</th>
                  <th className="px-4 py-3 font-medium">Take home (GHS)</th>
                </tr>
              </thead>
              <tbody>
                {employees.map((employee) => {
                  const rowInput = rowInputs[employee.employee_id];
                  const preview = rateSet ? previewSalary(rowInput.basic_salary, rowInput.flat_allowance, rateSet) : null;
                  const fieldPrefix = `salaries[${employee.employee_id}]`;
                  const rowError =
                    serverFieldErrors[`${fieldPrefix}[basic_salary]`] ??
                    serverFieldErrors[`${fieldPrefix}[flat_allowance]`] ??
                    preview?.error ??
                    null;
                  const figures = preview?.figures ?? null;
                  const isFixed = isFixedTakeHome(employee.employee_id);
                  const fixedTakeHome = fixedTakeHomes[employee.employee_id] ?? "";
                  const fixedTakeHomeAmount = readAmount(fixedTakeHome);
                  const takeHomeError = !isFixed
                    ? null
                    : (derivationErrors[employee.employee_id] ??
                      (figures !== null && fixedTakeHomeAmount !== null && figures.net_pay !== fixedTakeHomeAmount
                        ? `Closest take home possible is ${formatMoney(figures.net_pay)}.`
                        : null));

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
                          onChange={(changeEvent) => changeBasicSalary(employee.employee_id, changeEvent.target.value)}
                        />
                      </td>
                      <td className="px-2 py-2">
                        <input
                          type="number"
                          min="0"
                          step="0.01"
                          inputMode="decimal"
                          aria-label={`Allowance for ${fullName(employee)}`}
                          className={`${INPUT_CLASSES} w-32`}
                          disabled={!canEdit}
                          value={rowInput.flat_allowance}
                          onChange={(changeEvent) => changeAllowance(employee.employee_id, changeEvent.target.value)}
                        />
                        {rowError ? <p className="mt-1 max-w-56 text-sm text-refusal">{rowError}</p> : null}
                      </td>
                      <td className="figure px-3 py-3">{formatMoney(figures?.gross_pay)}</td>
                      <td className="figure px-3 py-3">{formatMoney(figures?.employee_ssnit)}</td>
                      <td className="figure px-3 py-3">{formatMoney(figures?.chargeable_income)}</td>
                      <td className="figure px-3 py-3">{formatMoney(figures?.paye)}</td>
                      <td className="px-2 py-2">
                        <select
                          aria-label={`Take home entry for ${fullName(employee)}`}
                          className={`${INPUT_CLASSES} w-28`}
                          disabled={!canEdit}
                          value={isFixed ? "fixed" : "derived"}
                          onChange={(changeEvent) =>
                            changeTakeHomeMode(employee.employee_id, changeEvent.target.value as TakeHomeMode, figures?.net_pay ?? null)
                          }
                        >
                          <option value="derived">Derived</option>
                          <option value="fixed">Fixed</option>
                        </select>
                      </td>
                      <td className="px-4 py-2">
                        {isFixed ? (
                          <>
                            <input
                              type="number"
                              min="0"
                              step="0.01"
                              inputMode="decimal"
                              aria-label={`Fixed take home for ${fullName(employee)}`}
                              className={`${INPUT_CLASSES} w-32 font-semibold`}
                              disabled={!canEdit}
                              value={fixedTakeHome}
                              onChange={(changeEvent) => changeFixedTakeHome(employee.employee_id, changeEvent.target.value)}
                            />
                            {takeHomeError ? <p className="mt-1 max-w-56 text-sm text-refusal">{takeHomeError}</p> : null}
                          </>
                        ) : (
                          <span className="figure block py-1 font-semibold">{formatMoney(figures?.net_pay)}</span>
                        )}
                      </td>
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
