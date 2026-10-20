// Salaries: one form for every active employee of the client whose books are open, with a column for the
// basic salary and for each of the client's allowances and deductions. Each row works its pay out again as
// the figures are typed.

import { useMemo, useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { formatMoney, formatPercent, fullName } from "../lib/formatting";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { allowanceForTakeHome, basicSalaryForTakeHome, previewSalary, readAmount } from "../lib/salaryPreview";
import type { SalaryRowInput, TakeHomeDerivation } from "../lib/salaryPreview";
import { Button, Dialog, EmptyState, INPUT_CLASSES, LoadingState, Notice, PageHeading, Panel } from "../shared/ui";
import type { PayItem, RateSet, ReliefType, SalariedEmployee } from "../types";

interface SalaryListAnswer {
  employees: SalariedEmployee[];
  rate_set: RateSet | null;
  pay_items: PayItem[];
  relief_types: ReliefType[];
}

/** Derived: take home follows the figures typed. Fixed: basic salary (or the first allowance) follows the take home. */
type TakeHomeMode = "derived" | "fixed";

/** "4000.00" → "4000.00"; null → "". */
function amountInputValue(storedAmount: string | number | null | undefined): string {
  return storedAmount === null || storedAmount === undefined ? "" : Number(storedAmount).toFixed(2);
}

function rowInputOf(employee: SalariedEmployee, payItems: PayItem[]): SalaryRowInput {
  return {
    basic_salary: amountInputValue(employee.basic_salary),
    tier3_percent: employee.tier3_percent === null || Number(employee.tier3_percent) === 0 ? "" : formatPercent(employee.tier3_percent),
    pay_items: Object.fromEntries(
      payItems.map((payItem) => [payItem.pay_item_id, amountInputValue(employee.pay_item_amounts[payItem.pay_item_id])]),
    ),
    reliefs: { ...employee.relief_claims },
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
            <>
              <Link
                to="../pay-items"
                className="inline-flex items-center rounded-md border border-rule bg-surface px-3.5 py-2 text-sm font-medium hover:bg-brand-tint"
              >
                Allowances & deductions
              </Link>
              <Link
                to="import"
                className="inline-flex items-center rounded-md border border-rule bg-surface px-3.5 py-2 text-sm font-medium hover:bg-brand-tint"
              >
                Import from Excel
              </Link>
            </>
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
  const { employees, rate_set: rateSet, pay_items: payItems, relief_types: reliefTypes } = salaryList;
  const canEdit = !client.is_archived && rateSet !== null;

  const allowanceItems = payItems.filter((payItem) => payItem.item_kind === "allowance");
  const deductionItems = payItems.filter((payItem) => payItem.item_kind === "deduction");
  // In a fixed row, a typed basic salary is balanced by the first allowance.
  const balancingAllowance = allowanceItems[0] ?? null;
  const reliefLabels = useMemo(
    () => Object.fromEntries(reliefTypes.map((reliefType) => [reliefType.relief_code, reliefType.label])),
    [reliefTypes],
  );

  const [rowInputs, setRowInputs] = useState<Record<number, SalaryRowInput>>(() =>
    Object.fromEntries(employees.map((employee) => [employee.employee_id, rowInputOf(employee, payItems)])),
  );
  // How each row is entered, and the take home a fixed row holds to. Kept on this page only, never saved.
  const [takeHomeModes, setTakeHomeModes] = useState<Record<number, TakeHomeMode>>({});
  const [fixedTakeHomes, setFixedTakeHomes] = useState<Record<number, string>>({});
  const [derivationErrors, setDerivationErrors] = useState<Record<number, string | null>>({});
  const [tier3ReliefsEmployee, setTier3ReliefsEmployee] = useState<SalariedEmployee | null>(null);
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

  function isFixedTakeHome(employeeId: number): boolean {
    return takeHomeModes[employeeId] === "fixed";
  }

  /**
   * Sets a row and, in a fixed row, works out the figure that balances it: the first allowance when the
   * basic salary was typed, the basic salary otherwise. A blank take home derives nothing.
   */
  function changeRow(employeeId: number, changedRow: SalaryRowInput, typedBasicSalary: boolean, takeHomeInput?: string) {
    const fixedTakeHome = takeHomeInput ?? fixedTakeHomes[employeeId] ?? "";
    let balancedRow = changedRow;
    let derivationError: string | null = null;

    if (rateSet !== null && isFixedTakeHome(employeeId) && fixedTakeHome.trim() !== "") {
      let derivation: TakeHomeDerivation | null = null;
      if (!typedBasicSalary) {
        derivation = basicSalaryForTakeHome(fixedTakeHome, changedRow, payItems, rateSet, reliefLabels);
        if (derivation.amount !== null) {
          balancedRow = { ...changedRow, basic_salary: derivation.amount.toFixed(2) };
        }
      } else if (balancingAllowance !== null && changedRow.basic_salary.trim() !== "") {
        // A blank basic salary means the row is not saved at all, so there is no allowance to work out.
        derivation = allowanceForTakeHome(fixedTakeHome, balancingAllowance.pay_item_id, changedRow, payItems, rateSet, reliefLabels);
        if (derivation.amount !== null) {
          balancedRow = {
            ...changedRow,
            pay_items: { ...changedRow.pay_items, [balancingAllowance.pay_item_id]: derivation.amount.toFixed(2) },
          };
        }
      }
      derivationError = derivation?.error ?? null;
    }

    setRowInputs((currentInputs) => ({ ...currentInputs, [employeeId]: balancedRow }));
    setDerivationErrors((currentErrors) => ({ ...currentErrors, [employeeId]: derivationError }));
  }

  function changeFixedTakeHome(employeeId: number, typedTakeHome: string) {
    setFixedTakeHomes((currentTakeHomes) => ({ ...currentTakeHomes, [employeeId]: typedTakeHome }));
    changeRow(employeeId, rowInputs[employeeId], false, typedTakeHome);
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

  const amountInput = (employee: SalariedEmployee, label: string, value: string, onChange: (typedAmount: string) => void) => (
    <input
      type="number"
      min="0"
      step="0.01"
      inputMode="decimal"
      aria-label={`${label} for ${fullName(employee)}`}
      className={`${INPUT_CLASSES} w-28`}
      disabled={!canEdit}
      value={value}
      onChange={(changeEvent) => onChange(changeEvent.target.value)}
    />
  );

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
                  {allowanceItems.map((payItem) => (
                    <th key={payItem.pay_item_id} className="px-2 py-3 font-medium">
                      {payItem.item_name}
                      {payItem.is_taxable ? null : <span className="block text-xs font-normal text-success">Non-taxable</span>}
                    </th>
                  ))}
                  {deductionItems.map((payItem) => (
                    <th key={payItem.pay_item_id} className="px-2 py-3 font-medium">
                      {payItem.item_name}
                      <span className="block text-xs font-normal">Deduction</span>
                    </th>
                  ))}
                  <th className="px-2 py-3 font-medium">Tier 3 & reliefs</th>
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
                  const employeeId = employee.employee_id;
                  const rowInput = rowInputs[employeeId];
                  const preview = rateSet ? previewSalary(rowInput, payItems, rateSet, reliefLabels) : null;
                  const fieldPrefix = `salaries[${employeeId}]`;
                  const serverRowErrorKey = Object.keys(serverFieldErrors).find((fieldName) => fieldName.startsWith(fieldPrefix));
                  const rowError = (serverRowErrorKey ? serverFieldErrors[serverRowErrorKey] : null) ?? preview?.error ?? null;
                  const figures = preview?.figures ?? null;
                  const isFixed = isFixedTakeHome(employeeId);
                  const fixedTakeHome = fixedTakeHomes[employeeId] ?? "";
                  const fixedTakeHomeAmount = readAmount(fixedTakeHome);
                  const takeHomeError = !isFixed
                    ? null
                    : (derivationErrors[employeeId] ??
                      (figures !== null && fixedTakeHomeAmount !== null && figures.net_pay !== fixedTakeHomeAmount
                        ? `Closest take home possible is ${formatMoney(figures.net_pay)}.`
                        : null));
                  const reliefClaimCount = Object.values(rowInput.reliefs).filter((claimCount) => claimCount > 0).length;
                  const tier3Percent = readAmount(rowInput.tier3_percent) ?? 0;

                  return (
                    <tr key={employeeId} className="border-b border-rule/60 align-top last:border-0">
                      <td className="px-4 py-3">
                        <span className="font-medium">{fullName(employee)}</span>
                        {employee.job_title_name ? <span className="block text-ink-soft">{employee.job_title_name}</span> : null}
                        {rowError ? <p className="mt-1 max-w-56 text-sm text-refusal">{rowError}</p> : null}
                      </td>
                      <td className="px-2 py-2">
                        {amountInput(employee, "Basic salary", rowInput.basic_salary, (typedAmount) =>
                          changeRow(employeeId, { ...rowInput, basic_salary: typedAmount }, true),
                        )}
                      </td>
                      {[...allowanceItems, ...deductionItems].map((payItem) => (
                        <td key={payItem.pay_item_id} className="px-2 py-2">
                          {amountInput(employee, payItem.item_name, rowInput.pay_items[payItem.pay_item_id] ?? "", (typedAmount) =>
                            changeRow(employeeId, { ...rowInput, pay_items: { ...rowInput.pay_items, [payItem.pay_item_id]: typedAmount } }, false),
                          )}
                        </td>
                      ))}
                      <td className="px-2 py-2">
                        <Button disabled={!canEdit} onClick={() => setTier3ReliefsEmployee(employee)} className="whitespace-nowrap">
                          {tier3Percent > 0 || reliefClaimCount > 0
                            ? [tier3Percent > 0 ? `Tier 3 ${formatPercent(tier3Percent)}%` : null, reliefClaimCount > 0 ? `${reliefClaimCount} relief${reliefClaimCount === 1 ? "" : "s"}` : null]
                                .filter(Boolean)
                                .join(", ")
                            : "None"}
                        </Button>
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
                            changeTakeHomeMode(employeeId, changeEvent.target.value as TakeHomeMode, figures?.net_pay ?? null)
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
                              onChange={(changeEvent) => changeFixedTakeHome(employeeId, changeEvent.target.value)}
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

      {rateSet !== null ? (
        <Tier3ReliefsDialog
          key={tier3ReliefsEmployee?.employee_id ?? "closed"}
          employee={tier3ReliefsEmployee}
          rowInput={tier3ReliefsEmployee ? rowInputs[tier3ReliefsEmployee.employee_id] : null}
          rateSet={rateSet}
          reliefTypes={reliefTypes}
          onClose={() => setTier3ReliefsEmployee(null)}
          onApply={(employeeId, changedRow) => {
            changeRow(employeeId, changedRow, false);
            setTier3ReliefsEmployee(null);
          }}
        />
      ) : null}
    </form>
  );
}

interface Tier3ReliefsDialogProps {
  employee: SalariedEmployee | null;
  rowInput: SalaryRowInput | null;
  rateSet: RateSet;
  reliefTypes: ReliefType[];
  onClose: () => void;
  onApply: (employeeId: number, changedRow: SalaryRowInput) => void;
}

/** One employee's voluntary Tier 3 and personal reliefs; applied to the row, saved with the salaries. */
function Tier3ReliefsDialog({ employee, rowInput, rateSet, reliefTypes, onClose, onApply }: Tier3ReliefsDialogProps) {
  const [tier3Percent, setTier3Percent] = useState(rowInput?.tier3_percent ?? "");
  const [reliefClaims, setReliefClaims] = useState<Record<string, number>>(rowInput?.reliefs ?? {});
  const claimableReliefs = reliefTypes.filter((reliefType) => rateSet.reliefs[reliefType.relief_code] !== undefined);

  function setClaimCount(reliefCode: string, claimCount: number) {
    setReliefClaims((currentClaims) => ({ ...currentClaims, [reliefCode]: claimCount }));
  }

  return (
    <Dialog isOpen={employee !== null} title={employee ? `${fullName(employee)}: Tier 3 & reliefs` : ""} onClose={onClose}>
      <div className="space-y-5">
        <label className="block text-sm">
          <span className="mb-1 block font-medium">Voluntary Tier 3 (% of basic salary)</span>
          <input
            type="number"
            min="0"
            max="100"
            step="0.01"
            inputMode="decimal"
            className={`${INPUT_CLASSES} w-32`}
            value={tier3Percent}
            onChange={(changeEvent) => setTier3Percent(changeEvent.target.value)}
          />
          <span className="mt-1 block text-ink-soft">Tax-relieved up to {formatPercent(rateSet.tier3_relief_cap_percent)}%</span>
        </label>

        <fieldset>
          <legend className="mb-2 text-sm font-medium">Personal reliefs</legend>
          <div className="space-y-2">
            {claimableReliefs.map((reliefType) => {
              const reliefRate = rateSet.reliefs[reliefType.relief_code];
              const claimCount = reliefClaims[reliefType.relief_code] ?? 0;
              const worth =
                reliefRate.percent_of_assessable !== null
                  ? `${formatPercent(reliefRate.percent_of_assessable)}% of assessable income`
                  : `GHS ${formatMoney(reliefRate.annual_amount)} a year${reliefRate.max_claims > 1 ? " each" : ""}`;
              return (
                <div key={reliefType.relief_code} className="flex items-center justify-between gap-3 text-sm">
                  <label className="flex items-center gap-2">
                    {reliefRate.max_claims > 1 ? null : (
                      <input
                        type="checkbox"
                        checked={claimCount > 0}
                        onChange={(changeEvent) => setClaimCount(reliefType.relief_code, changeEvent.target.checked ? 1 : 0)}
                      />
                    )}
                    <span>
                      {reliefType.label}
                      <span className="block text-ink-soft">{worth}</span>
                    </span>
                  </label>
                  {reliefRate.max_claims > 1 ? (
                    <select
                      aria-label={`${reliefType.label} claims`}
                      className={`${INPUT_CLASSES} w-20`}
                      value={claimCount}
                      onChange={(changeEvent) => setClaimCount(reliefType.relief_code, Number(changeEvent.target.value))}
                    >
                      {Array.from({ length: reliefRate.max_claims + 1 }, (_, count) => (
                        <option key={count} value={count}>
                          {count}
                        </option>
                      ))}
                    </select>
                  ) : null}
                </div>
              );
            })}
          </div>
        </fieldset>

        <div className="flex justify-end gap-2 pt-2">
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            onClick={() => employee && rowInput && onApply(employee.employee_id, { ...rowInput, tier3_percent: tier3Percent, reliefs: reliefClaims })}
          >
            Apply
          </Button>
        </div>
      </div>
    </Dialog>
  );
}
