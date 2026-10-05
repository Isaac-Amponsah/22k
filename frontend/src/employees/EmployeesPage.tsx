// The employees of the client whose books are open.

import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { formatDate, fullName } from "../lib/formatting";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { Button, Dialog, EmptyState, LoadingState, Notice, PageHeading, Panel, TextField } from "../shared/ui";
import type { Employee } from "../types";

interface EmployeeFormValues {
  employee_code: string;
  first_name: string;
  last_name: string;
  job_title_name: string;
  department_name: string;
  hire_date: string;
  ssnit_number: string;
  tax_identification_number: string;
  is_active: boolean;
}

function formValuesOf(employee: Employee | null): EmployeeFormValues {
  return {
    employee_code: employee?.employee_code ?? "",
    first_name: employee?.first_name ?? "",
    last_name: employee?.last_name ?? "",
    job_title_name: employee?.job_title_name ?? "",
    department_name: employee?.department_name ?? "",
    hire_date: employee?.hire_date ?? "",
    ssnit_number: employee?.ssnit_number ?? "",
    tax_identification_number: employee?.tax_identification_number ?? "",
    is_active: employee?.is_active ?? true,
  };
}

export function EmployeesPage() {
  const client = useClientInScope();
  const [formTarget, setFormTarget] = useState<Employee | "new" | null>(null);
  const [successMessage, setSuccessMessage] = useState("");

  const employeesQuery = useQuery({
    queryKey: queryKeys.employees(client.client_id),
    queryFn: async () => (await api.get<{ employees: Employee[] }>(clientApiPath(client.client_id, "employees"))).data.employees,
  });

  const employees = employeesQuery.data ?? [];

  return (
    <>
      <PageHeading
        title="Employees"
        actions={
          <Button variant="primary" onClick={() => setFormTarget("new")} disabled={client.is_archived}>
            Add employee
          </Button>
        }
      />

      {successMessage ? (
        <div className="mb-4">
          <Notice tone="success">{successMessage}</Notice>
        </div>
      ) : null}
      {employeesQuery.isError ? <Notice tone="refusal">{errorMessage(employeesQuery.error)}</Notice> : null}

      <Panel>
        {employeesQuery.isPending ? (
          <LoadingState />
        ) : employees.length === 0 ? (
          <EmptyState>No employees yet. Add one here, or import a payroll sheet under Salaries to add them from it.</EmptyState>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="border-b border-rule text-left text-ink-soft">
                <tr>
                  <th className="px-4 py-3 font-medium">Name</th>
                  <th className="px-4 py-3 font-medium">Code</th>
                  <th className="px-4 py-3 font-medium">Job title</th>
                  <th className="px-4 py-3 font-medium">Department</th>
                  <th className="px-4 py-3 font-medium">Hired</th>
                  <th className="px-4 py-3 font-medium">SSNIT number</th>
                  <th className="px-4 py-3 font-medium">Status</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody>
                {employees.map((employee) => (
                  <tr key={employee.employee_id} className="border-b border-rule/60 last:border-0">
                    <td className="px-4 py-3 font-medium">{fullName(employee)}</td>
                    <td className="px-4 py-3">{employee.employee_code ?? "—"}</td>
                    <td className="px-4 py-3">{employee.job_title_name ?? "—"}</td>
                    <td className="px-4 py-3">{employee.department_name ?? "—"}</td>
                    <td className="px-4 py-3">{formatDate(employee.hire_date)}</td>
                    <td className="px-4 py-3">{employee.ssnit_number ?? "—"}</td>
                    <td className="px-4 py-3">{employee.is_active ? "Active" : <span className="text-ink-soft">Not active</span>}</td>
                    <td className="px-4 py-3 text-right">
                      <Button onClick={() => setFormTarget(employee)} disabled={client.is_archived}>
                        Edit
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>

      <EmployeeFormDialog
        key={formTarget === null ? "closed" : formTarget === "new" ? "new" : formTarget.employee_id}
        clientId={client.client_id}
        target={formTarget}
        onClose={() => setFormTarget(null)}
        onSaved={(message) => {
          setFormTarget(null);
          setSuccessMessage(message);
        }}
      />
    </>
  );
}

interface EmployeeFormDialogProps {
  clientId: number;
  target: Employee | "new" | null;
  onClose: () => void;
  onSaved: (message: string) => void;
}

function EmployeeFormDialog({ clientId, target, onClose, onSaved }: EmployeeFormDialogProps) {
  const queryClient = useQueryClient();
  const editedEmployee = target === "new" ? null : target;
  const [formValues, setFormValues] = useState<EmployeeFormValues>(() => formValuesOf(editedEmployee));

  const saveMutation = useMutation({
    mutationFn: () =>
      editedEmployee
        ? api.patch(clientApiPath(clientId, `employees/${editedEmployee.employee_id}`), formValues)
        : api.post(clientApiPath(clientId, "employees"), formValues),
    onSuccess: async (result) => {
      // Salaries and draft runs list employees too.
      await queryClient.invalidateQueries({ queryKey: queryKeys.allOfClient(clientId) });
      onSaved(result.message);
    },
  });

  const fieldErrors = fieldErrorsOf(saveMutation.error);
  type TextFieldName = Exclude<keyof EmployeeFormValues, "is_active">;
  const fieldProps = (fieldName: TextFieldName) => ({
    name: fieldName,
    value: formValues[fieldName],
    error: fieldErrors[fieldName],
    onChange: (changeEvent: { target: { value: string } }) => setFormValues({ ...formValues, [fieldName]: changeEvent.target.value }),
  });

  function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    saveMutation.mutate();
  }

  return (
    <Dialog isOpen={target !== null} title={editedEmployee ? "Edit employee" : "Add an employee"} onClose={onClose}>
      <form onSubmit={handleSubmit} className="space-y-4">
        {saveMutation.isError && Object.keys(fieldErrors).length === 0 ? (
          <Notice tone="refusal">{errorMessage(saveMutation.error)}</Notice>
        ) : null}
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="First name" required maxLength={100} autoFocus {...fieldProps("first_name")} />
          <TextField label="Last name" required maxLength={100} {...fieldProps("last_name")} />
          <TextField label="Employee code" maxLength={50} {...fieldProps("employee_code")} />
          <TextField label="Hire date" type="date" {...fieldProps("hire_date")} />
          <TextField label="Job title" maxLength={100} {...fieldProps("job_title_name")} />
          <TextField label="Department" maxLength={100} {...fieldProps("department_name")} />
          <TextField label="SSNIT number" maxLength={30} {...fieldProps("ssnit_number")} />
          <TextField label="TIN" maxLength={30} {...fieldProps("tax_identification_number")} />
        </div>
        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={formValues.is_active}
            onChange={(changeEvent) => setFormValues({ ...formValues, is_active: changeEvent.target.checked })}
          />
          Active
        </label>
        <div className="flex justify-end gap-2 pt-2">
          <Button onClick={onClose} disabled={saveMutation.isPending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={saveMutation.isPending}>
            {saveMutation.isPending ? "Saving…" : editedEmployee ? "Save employee" : "Add employee"}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
