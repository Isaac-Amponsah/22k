// Import salaries from a payroll workbook: upload, review each row against the client's employees,
// then save. The rows live only in this page; leaving it (or opening another client) discards them.

import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage } from "../lib/apiClient";
import { formatMoney, fullName } from "../lib/formatting";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { Button, ConfirmDialog, INPUT_CLASSES, Notice, PageHeading, Panel } from "../shared/ui";
import type { AllowanceMode, ImportedSalaryRow, SalariedEmployee } from "../types";

interface ParsedSheet {
  rows: ImportedSalaryRow[];
  employees: SalariedEmployee[];
}

/** What the accountant decides for one sheet row. employeeChoice: "" (undecided), "new", or an employee id. */
interface RowChoice {
  isIncluded: boolean;
  employeeChoice: string;
  allowanceMode: AllowanceMode;
}

const ADD_AS_NEW_EMPLOYEE = "new";

export function SalaryImportPage() {
  const client = useClientInScope();
  const navigate = useNavigate();
  const queryClient = useQueryClient();

  const [parsedSheet, setParsedSheet] = useState<ParsedSheet | null>(null);
  const [rowChoices, setRowChoices] = useState<RowChoice[]>([]);
  const [isConfirmOpen, setIsConfirmOpen] = useState(false);

  const parseMutation = useMutation({
    mutationFn: async (workbook: File) => {
      const upload = new FormData();
      upload.append("payroll_file", workbook);
      return (await api.post<ParsedSheet>(clientApiPath(client.client_id, "salaries/import/parse"), upload)).data;
    },
    onSuccess: (sheet) => {
      setParsedSheet(sheet);
      setRowChoices(
        sheet.rows.map((row) => ({
          isIncluded: row.matched_employee_id !== null,
          employeeChoice: row.matched_employee_id === null ? "" : String(row.matched_employee_id),
          allowanceMode: row.allowance_mode,
        })),
      );
    },
  });

  const confirmMutation = useMutation({
    mutationFn: () => {
      const chosenRows = (parsedSheet?.rows ?? [])
        .map((row, rowIndex) => ({ row, choice: rowChoices[rowIndex] }))
        .filter(({ choice }) => choice.isIncluded)
        .map(({ row, choice }) => ({
          sheet_row: row.sheet_row,
          employee_name: row.employee_name,
          employee_id: choice.employeeChoice,
          basic_salary: row.basic_salary,
          allowance_mode: choice.allowanceMode,
          flat_allowance: row.flat_allowance,
          target_chargeable_income: row.target_chargeable_income,
        }));
      return api.post(clientApiPath(client.client_id, "salaries/import/confirm"), { rows: chosenRows });
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.allOfClient(client.client_id) });
      navigate("..", { relative: "path" });
    },
    onSettled: () => setIsConfirmOpen(false),
  });

  function handleUpload(submitEvent: FormEvent<HTMLFormElement>) {
    submitEvent.preventDefault();
    const workbook = new FormData(submitEvent.currentTarget).get("payroll_file");
    if (workbook instanceof File && workbook.size > 0) {
      parseMutation.mutate(workbook);
    }
  }

  function changeChoice(rowIndex: number, changedChoice: Partial<RowChoice>) {
    setRowChoices((currentChoices) =>
      currentChoices.map((choice, choiceIndex) => (choiceIndex === rowIndex ? { ...choice, ...changedChoice } : choice)),
    );
  }

  const includedCount = rowChoices.filter((choice) => choice.isIncluded).length;
  const unmatchedCount = parsedSheet?.rows.filter((row) => row.matched_employee_id === null).length ?? 0;

  return (
    <>
      <PageHeading
        title="Import salaries from Excel"
        actions={
          <Link to=".." relative="path" className="inline-flex items-center rounded-md border border-rule bg-surface px-3.5 py-2 text-sm font-medium">
            Back to salaries
          </Link>
        }
      />

      {parsedSheet === null ? (
        <Panel className="max-w-xl p-6">
          <form onSubmit={handleUpload} className="space-y-4">
            {parseMutation.isError ? <Notice tone="refusal">{errorMessage(parseMutation.error)}</Notice> : null}
            <div>
              <label htmlFor="payroll_file" className="mb-1 block text-sm font-medium">
                Payroll workbook (.xlsx, up to 2 MB)
              </label>
              <input
                id="payroll_file"
                name="payroll_file"
                type="file"
                required
                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                className={INPUT_CLASSES}
              />
            </div>
            <Button type="submit" variant="primary" disabled={parseMutation.isPending || client.is_archived}>
              {parseMutation.isPending ? "Reading…" : "Read sheet"}
            </Button>
          </form>
        </Panel>
      ) : (
        <div className="space-y-4">
          {confirmMutation.isError ? <Notice tone="refusal">{errorMessage(confirmMutation.error)}</Notice> : null}
          <Panel>
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-4 py-3">
              <p className="text-sm text-ink-soft">
                {parsedSheet.rows.length} rows read,{" "}
                <span className={unmatchedCount > 0 ? "font-medium text-attention" : ""}>{unmatchedCount} not matched to an employee</span>.
              </p>
              <div className="flex gap-2">
                <Button onClick={() => setParsedSheet(null)}>Upload a different file</Button>
                <Button variant="primary" disabled={includedCount === 0} onClick={() => setIsConfirmOpen(true)}>
                  Import {includedCount} {includedCount === 1 ? "salary" : "salaries"}
                </Button>
              </div>
            </div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="border-b border-rule text-left text-ink-soft">
                  <tr>
                    <th className="px-4 py-3 font-medium">Import</th>
                    <th className="px-2 py-3 font-medium">Row</th>
                    <th className="px-2 py-3 font-medium">Name on sheet</th>
                    <th className="px-2 py-3 font-medium">Employee</th>
                    <th className="figure px-3 py-3 font-medium">Basic salary</th>
                    <th className="px-2 py-3 font-medium">Allowance</th>
                    <th className="figure px-3 py-3 font-medium">Flat amount</th>
                    <th className="figure px-4 py-3 font-medium">Target chargeable</th>
                  </tr>
                </thead>
                <tbody>
                  {parsedSheet.rows.map((row, rowIndex) => {
                    const choice = rowChoices[rowIndex];
                    return (
                      <tr
                        key={row.sheet_row}
                        className={`border-b border-rule/60 last:border-0 ${row.matched_employee_id === null ? "bg-attention-tint/60" : ""}`}
                      >
                        <td className="px-4 py-2">
                          <input
                            type="checkbox"
                            aria-label={`Import row ${row.sheet_row}`}
                            checked={choice.isIncluded}
                            onChange={(changeEvent) => changeChoice(rowIndex, { isIncluded: changeEvent.target.checked })}
                          />
                        </td>
                        <td className="px-2 py-2 text-ink-soft">{row.sheet_row}</td>
                        <td className="px-2 py-2">{row.employee_name}</td>
                        <td className="px-2 py-2">
                          <select
                            aria-label={`Employee for row ${row.sheet_row}`}
                            className={`${INPUT_CLASSES} w-56`}
                            value={choice.employeeChoice}
                            onChange={(changeEvent) => changeChoice(rowIndex, { employeeChoice: changeEvent.target.value })}
                          >
                            <option value="">Choose employee…</option>
                            <option value={ADD_AS_NEW_EMPLOYEE}>Add as a new employee</option>
                            {parsedSheet.employees.map((employee) => (
                              <option key={employee.employee_id} value={employee.employee_id}>
                                {fullName(employee)}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td className="figure px-3 py-2">{formatMoney(row.basic_salary)}</td>
                        <td className="px-2 py-2">
                          <select
                            aria-label={`Allowance type for row ${row.sheet_row}`}
                            className={`${INPUT_CLASSES} w-44`}
                            value={choice.allowanceMode}
                            onChange={(changeEvent) => changeChoice(rowIndex, { allowanceMode: changeEvent.target.value as AllowanceMode })}
                          >
                            <option value="flat">Flat amount</option>
                            <option value="target" disabled={row.target_chargeable_income === null}>
                              Top up to target
                            </option>
                          </select>
                        </td>
                        <td className="figure px-3 py-2">{formatMoney(row.flat_allowance)}</td>
                        <td className="figure px-4 py-2">{formatMoney(row.target_chargeable_income)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          </Panel>
        </div>
      )}

      <ConfirmDialog
        isOpen={isConfirmOpen}
        title={`Import into ${client.client_name}?`}
        explanation={`${includedCount} ${includedCount === 1 ? "salary" : "salaries"} will be saved to ${client.client_name}. An employee's existing salary is replaced.`}
        confirmLabel="Import salaries"
        isWorking={confirmMutation.isPending}
        onConfirm={() => confirmMutation.mutate()}
        onCancel={() => setIsConfirmOpen(false)}
      />
    </>
  );
}
