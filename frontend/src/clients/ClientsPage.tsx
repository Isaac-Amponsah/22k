// The accountant's shelf of clients: open one, add one, edit, archive or restore.

import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { clientBinder } from "../lib/clientBinder";
import { queryKeys } from "../lib/queryKeys";
import { Button, ConfirmDialog, Dialog, EmptyState, LoadingState, Notice, PageHeading, TextField } from "../shared/ui";
import type { Client } from "../types";

type ClientFormValues = Record<
  "client_name" | "tax_identification_number" | "ssnit_employer_number" | "contact_email" | "contact_phone" | "postal_address",
  string
>;

function formValuesOf(client: Client | null): ClientFormValues {
  return {
    client_name: client?.client_name ?? "",
    tax_identification_number: client?.tax_identification_number ?? "",
    ssnit_employer_number: client?.ssnit_employer_number ?? "",
    contact_email: client?.contact_email ?? "",
    contact_phone: client?.contact_phone ?? "",
    postal_address: client?.postal_address ?? "",
  };
}

export function ClientsPage() {
  const queryClient = useQueryClient();
  const [formTarget, setFormTarget] = useState<Client | "new" | null>(null);
  const [archiveTarget, setArchiveTarget] = useState<Client | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "refusal"; text: string } | null>(null);

  const clientsQuery = useQuery({
    queryKey: queryKeys.clients(),
    queryFn: async () => (await api.get<{ clients: Client[] }>("/api/clients")).data.clients,
  });

  const archiveMutation = useMutation({
    mutationFn: (client: Client) => api.post(`/api/clients/${client.client_id}/${client.is_archived ? "restore" : "archive"}`),
    onSuccess: (result) => {
      setNotice({ tone: "success", text: result.message });
      return queryClient.invalidateQueries({ queryKey: queryKeys.clients() });
    },
    onError: (thrown) => setNotice({ tone: "refusal", text: errorMessage(thrown) }),
    onSettled: () => setArchiveTarget(null),
  });

  const clients = clientsQuery.data ?? [];
  const activeClients = clients.filter((client) => !client.is_archived);
  const archivedClients = clients.filter((client) => client.is_archived);

  return (
    <main className="mx-auto max-w-7xl px-4 py-8">
      <PageHeading
        title="Your clients"
        actions={
          <Button variant="primary" onClick={() => setFormTarget("new")}>
            Add client
          </Button>
        }
      />

      {notice ? (
        <div className="mb-4">
          <Notice tone={notice.tone}>{notice.text}</Notice>
        </div>
      ) : null}
      {clientsQuery.isError ? <Notice tone="refusal">{errorMessage(clientsQuery.error)}</Notice> : null}
      {clientsQuery.isPending ? <LoadingState /> : null}

      {clientsQuery.isSuccess && clients.length === 0 ? (
        <div className="rounded-lg border border-dashed border-rule bg-surface">
          <EmptyState>No clients yet. Add the first business you keep payroll for.</EmptyState>
        </div>
      ) : null}

      <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        {activeClients.map((client) => (
          <ClientBinderCard key={client.client_id} client={client} onEdit={setFormTarget} onArchive={setArchiveTarget} />
        ))}
      </ul>

      {archivedClients.length > 0 ? (
        <>
          <h2 className="mt-10 mb-3 font-serif text-lg font-semibold text-ink-soft">Archived</h2>
          <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {archivedClients.map((client) => (
              <ClientBinderCard key={client.client_id} client={client} onEdit={setFormTarget} onArchive={setArchiveTarget} />
            ))}
          </ul>
        </>
      ) : null}

      <ClientFormDialog
        key={formTarget === null ? "closed" : formTarget === "new" ? "new" : formTarget.client_id}
        target={formTarget}
        onClose={() => setFormTarget(null)}
        onSaved={(message) => {
          setFormTarget(null);
          setNotice({ tone: "success", text: message });
        }}
      />

      <ConfirmDialog
        isOpen={archiveTarget !== null}
        title={archiveTarget?.is_archived ? "Restore this client?" : "Archive this client?"}
        explanation={
          archiveTarget?.is_archived
            ? `${archiveTarget.client_name} can be worked on again once restored.`
            : `${archiveTarget?.client_name ?? ""} keeps all its payroll records, but nothing in them can change until it is restored.`
        }
        confirmLabel={archiveTarget?.is_archived ? "Restore client" : "Archive client"}
        isWorking={archiveMutation.isPending}
        onConfirm={() => archiveTarget && archiveMutation.mutate(archiveTarget)}
        onCancel={() => setArchiveTarget(null)}
      />
    </main>
  );
}

interface ClientBinderCardProps {
  client: Client;
  onEdit: (client: Client) => void;
  onArchive: (client: Client) => void;
}

function ClientBinderCard({ client, onEdit, onArchive }: ClientBinderCardProps) {
  const binder = clientBinder(client.client_id);

  return (
    <li
      style={{ borderLeftColor: binder.spine }}
      className={`flex flex-col rounded-lg border border-l-[10px] border-rule bg-surface ${client.is_archived ? "opacity-70" : ""}`}
    >
      <Link to={`/clients/${client.client_id}`} className="block flex-1 rounded-tr-lg px-5 pt-5 pb-4 hover:bg-paper/60">
        <span style={{ color: binder.spine }} className="font-serif text-xl font-semibold leading-snug">
          {client.client_name}
        </span>
        <span className="mt-2 block text-sm text-ink-soft">
          {client.tax_identification_number ? `TIN ${client.tax_identification_number}` : "No TIN recorded"}
        </span>
      </Link>
      <div className="flex gap-2 border-t border-rule px-5 py-3">
        <Button onClick={() => onEdit(client)}>Edit details</Button>
        <Button onClick={() => onArchive(client)}>{client.is_archived ? "Restore" : "Archive"}</Button>
      </div>
    </li>
  );
}

interface ClientFormDialogProps {
  target: Client | "new" | null;
  onClose: () => void;
  onSaved: (message: string) => void;
}

function ClientFormDialog({ target, onClose, onSaved }: ClientFormDialogProps) {
  const queryClient = useQueryClient();
  const editedClient = target === "new" ? null : target;
  const [formValues, setFormValues] = useState<ClientFormValues>(() => formValuesOf(editedClient));

  const saveMutation = useMutation({
    mutationFn: () =>
      editedClient ? api.patch(`/api/clients/${editedClient.client_id}`, formValues) : api.post("/api/clients", formValues),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.clients() });
      onSaved(result.message);
    },
  });

  const fieldErrors = fieldErrorsOf(saveMutation.error);
  const fieldProps = (fieldName: keyof ClientFormValues) => ({
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
    <Dialog isOpen={target !== null} title={editedClient ? "Edit client details" : "Add a client"} onClose={onClose}>
      <form onSubmit={handleSubmit} className="space-y-4">
        {saveMutation.isError && Object.keys(fieldErrors).length === 0 ? (
          <Notice tone="refusal">{errorMessage(saveMutation.error)}</Notice>
        ) : null}
        <TextField label="Business name" required maxLength={150} autoFocus {...fieldProps("client_name")} />
        <div className="grid gap-4 sm:grid-cols-2">
          <TextField label="TIN" maxLength={30} {...fieldProps("tax_identification_number")} />
          <TextField label="SSNIT employer number" maxLength={30} {...fieldProps("ssnit_employer_number")} />
          <TextField label="Contact email" type="email" maxLength={190} {...fieldProps("contact_email")} />
          <TextField label="Contact phone" maxLength={30} {...fieldProps("contact_phone")} />
        </div>
        <TextField label="Postal address" maxLength={500} {...fieldProps("postal_address")} />
        <div className="flex justify-end gap-2 pt-2">
          <Button onClick={onClose} disabled={saveMutation.isPending}>
            Cancel
          </Button>
          <Button type="submit" variant="primary" disabled={saveMutation.isPending}>
            {saveMutation.isPending ? "Saving…" : editedClient ? "Save client" : "Add client"}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}
