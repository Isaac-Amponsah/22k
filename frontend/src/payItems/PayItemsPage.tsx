// The allowance and deduction types of the client whose books are open. Employees hold amounts on them
// on the Salaries page.

import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { ActionsMenu } from "../shared/ActionsMenu";
import { Button, ConfirmDialog, Dialog, EmptyState, LoadingState, Notice, PageHeading, Panel, TextField } from "../shared/ui";
import type { PayItem, PayItemKind } from "../types";

const KIND_HEADINGS: Record<PayItemKind, string> = { allowance: "Allowances", deduction: "Deductions" };

export function PayItemsPage() {
  const client = useClientInScope();
  const queryClient = useQueryClient();
  const [editTarget, setEditTarget] = useState<PayItem | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<PayItem | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "refusal"; text: string } | null>(null);

  const payItemsQuery = useQuery({
    queryKey: queryKeys.payItems(client.client_id),
    queryFn: async () => (await api.get<{ pay_items: PayItem[] }>(clientApiPath(client.client_id, "pay-items"))).data.pay_items,
  });

  const deleteMutation = useMutation({
    mutationFn: (payItem: PayItem) => api.delete(clientApiPath(client.client_id, `pay-items/${payItem.pay_item_id}`)),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.allOfClient(client.client_id) });
      setNotice({ tone: "success", text: result.message });
    },
    onError: (thrown) => setNotice({ tone: "refusal", text: errorMessage(thrown) }),
    onSettled: () => setDeleteTarget(null),
  });

  const payItems = payItemsQuery.data ?? [];

  return (
    <>
      <PageHeading title="Allowances & deductions" />

      {notice ? (
        <div className="mb-4">
          <Notice tone={notice.tone}>{notice.text}</Notice>
        </div>
      ) : null}
      {payItemsQuery.isError ? <Notice tone="refusal">{errorMessage(payItemsQuery.error)}</Notice> : null}

      {payItemsQuery.isPending ? (
        <LoadingState />
      ) : (
        <div className="grid gap-6 lg:grid-cols-2">
          {(["allowance", "deduction"] as const).map((itemKind) => {
            const itemsOfKind = payItems.filter((payItem) => payItem.item_kind === itemKind);
            return (
              <Panel key={itemKind}>
                <h2 className="border-b border-rule px-4 py-3 font-serif text-lg font-semibold">{KIND_HEADINGS[itemKind]}</h2>
                {itemsOfKind.length === 0 ? (
                  <EmptyState>No {KIND_HEADINGS[itemKind].toLowerCase()} yet.</EmptyState>
                ) : (
                  <table className="w-full text-sm">
                    <thead className="border-b border-rule text-left text-ink-soft">
                      <tr>
                        <th className="px-4 py-2 font-medium">Name</th>
                        {itemKind === "allowance" ? <th className="px-4 py-2 font-medium">Tax</th> : null}
                        <th className="figure px-4 py-2 font-medium">Employees</th>
                        <th className="px-4 py-2" />
                      </tr>
                    </thead>
                    <tbody>
                      {itemsOfKind.map((payItem) => (
                        <tr key={payItem.pay_item_id} className="border-b border-rule/60 last:border-0">
                          <td className="px-4 py-2.5 font-medium">{payItem.item_name}</td>
                          {itemKind === "allowance" ? (
                            <td className="px-4 py-2.5">{payItem.is_taxable ? "Taxable" : <span className="text-success">Non-taxable</span>}</td>
                          ) : null}
                          <td className="figure px-4 py-2.5">{payItem.employee_count}</td>
                          <td className="px-4 py-1.5 text-right">
                            <ActionsMenu
                              label={`Actions for ${payItem.item_name}`}
                              actions={[
                                { label: "Edit", onSelect: () => setEditTarget(payItem), isDisabled: client.is_archived },
                                {
                                  label: "Delete",
                                  onSelect: () => setDeleteTarget(payItem),
                                  isDangerous: true,
                                  isDisabled: client.is_archived || Number(payItem.employee_count) > 0,
                                },
                              ]}
                            />
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                )}
                {client.is_archived ? null : (
                  <AddPayItemForm
                    clientId={client.client_id}
                    itemKind={itemKind}
                    onAdded={(message) => setNotice({ tone: "success", text: message })}
                  />
                )}
              </Panel>
            );
          })}
        </div>
      )}

      <EditPayItemDialog
        key={editTarget?.pay_item_id ?? "closed"}
        clientId={client.client_id}
        payItem={editTarget}
        onClose={() => setEditTarget(null)}
        onSaved={(message) => {
          setEditTarget(null);
          setNotice({ tone: "success", text: message });
        }}
      />

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        title={`Delete ${deleteTarget?.item_name ?? ""}?`}
        explanation="This cannot be undone."
        confirmLabel="Delete"
        isDangerous
        isWorking={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget)}
        onCancel={() => setDeleteTarget(null)}
      />
    </>
  );
}

function AddPayItemForm({ clientId, itemKind, onAdded }: { clientId: number; itemKind: PayItemKind; onAdded: (message: string) => void }) {
  const queryClient = useQueryClient();
  const [itemName, setItemName] = useState("");
  const [isTaxable, setIsTaxable] = useState(true);

  const addMutation = useMutation({
    mutationFn: () => api.post(clientApiPath(clientId, "pay-items"), { item_kind: itemKind, item_name: itemName, is_taxable: isTaxable }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.allOfClient(clientId) });
      setItemName("");
      setIsTaxable(true);
      onAdded(result.message);
    },
  });

  const fieldErrors = fieldErrorsOf(addMutation.error);

  function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    addMutation.mutate();
  }

  return (
    <form onSubmit={handleSubmit} className="flex flex-wrap items-end gap-3 border-t border-rule px-4 py-3">
      <TextField
        className="min-w-48 flex-1"
        label={itemKind === "allowance" ? "New allowance" : "New deduction"}
        name={`new_${itemKind}_name`}
        maxLength={100}
        required
        value={itemName}
        error={fieldErrors.item_name ?? (addMutation.isError && Object.keys(fieldErrors).length === 0 ? errorMessage(addMutation.error) : undefined)}
        onChange={(changeEvent) => setItemName(changeEvent.target.value)}
      />
      {itemKind === "allowance" ? (
        <label className="flex items-center gap-2 pb-2 text-sm">
          <input type="checkbox" checked={isTaxable} onChange={(changeEvent) => setIsTaxable(changeEvent.target.checked)} />
          Taxable
        </label>
      ) : null}
      <Button type="submit" variant="primary" disabled={addMutation.isPending}>
        {addMutation.isPending ? "Adding…" : "Add"}
      </Button>
    </form>
  );
}

interface EditPayItemDialogProps {
  clientId: number;
  payItem: PayItem | null;
  onClose: () => void;
  onSaved: (message: string) => void;
}

function EditPayItemDialog({ clientId, payItem, onClose, onSaved }: EditPayItemDialogProps) {
  const queryClient = useQueryClient();
  const [itemName, setItemName] = useState(payItem?.item_name ?? "");
  const [isTaxable, setIsTaxable] = useState(payItem?.is_taxable ?? true);

  const saveMutation = useMutation({
    mutationFn: () => api.patch(clientApiPath(clientId, `pay-items/${payItem?.pay_item_id}`), { item_name: itemName, is_taxable: isTaxable }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.allOfClient(clientId) });
      onSaved(result.message);
    },
  });

  const fieldErrors = fieldErrorsOf(saveMutation.error);

  function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    saveMutation.mutate();
  }

  return (
    <Dialog isOpen={payItem !== null} title={payItem?.item_kind === "deduction" ? "Edit deduction" : "Edit allowance"} onClose={onClose}>
      <form onSubmit={handleSubmit} className="space-y-4">
        {saveMutation.isError && Object.keys(fieldErrors).length === 0 ? <Notice tone="refusal">{errorMessage(saveMutation.error)}</Notice> : null}
        <TextField
          label="Name"
          name="item_name"
          maxLength={100}
          required
          autoFocus
          value={itemName}
          error={fieldErrors.item_name}
          onChange={(changeEvent) => setItemName(changeEvent.target.value)}
        />
        {payItem?.item_kind === "allowance" ? (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={isTaxable} onChange={(changeEvent) => setIsTaxable(changeEvent.target.checked)} />
            Taxable
          </label>
        ) : null}
        <div className="flex justify-end gap-2 pt-2">
          <Button onClick={onClose} disabled={saveMutation.isPending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={saveMutation.isPending}>
            {saveMutation.isPending ? "Saving…" : "Save"}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
