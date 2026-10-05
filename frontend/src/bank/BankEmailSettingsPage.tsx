// How the open client's payroll reaches its bank: the bank, who receives the email, and the email's
// words. Placeholders in the words are filled in for each run when it is sent.

import { useRef, useState } from "react";
import type { FormEvent, SyntheticEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { useClientInScope } from "../clients/ClientWorkspace";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { Button, INPUT_CLASSES, LoadingState, Notice, PageHeading, Panel, TextField } from "../shared/ui";
import type { BankEmailSettings } from "../types";

interface BankEmailSettingsAnswer {
  settings: BankEmailSettings;
  /** Placeholder ("{client_name}") => what it stands for. */
  placeholders: Record<string, string>;
}

export function BankEmailSettingsPage() {
  const client = useClientInScope();
  const settingsPath = clientApiPath(client.client_id, "bank-email-settings");

  const settingsQuery = useQuery({
    queryKey: queryKeys.bankEmailSettings(client.client_id),
    queryFn: async () => (await api.get<BankEmailSettingsAnswer>(settingsPath)).data,
  });

  return (
    <>
      <PageHeading title="Bank" />
      {settingsQuery.isError ? <Notice tone="refusal">{errorMessage(settingsQuery.error)}</Notice> : null}
      {settingsQuery.isPending ? <LoadingState /> : null}
      {settingsQuery.isSuccess ? (
        <BankEmailSettingsForm
          clientId={client.client_id}
          isReadOnly={client.is_archived}
          savedSettings={settingsQuery.data.settings}
          placeholders={settingsQuery.data.placeholders}
        />
      ) : null}
    </>
  );
}

type TemplateField = "email_subject_template" | "email_body_template";

interface BankEmailSettingsFormProps {
  clientId: number;
  isReadOnly: boolean;
  savedSettings: BankEmailSettings;
  placeholders: Record<string, string>;
}

function BankEmailSettingsForm({ clientId, isReadOnly, savedSettings, placeholders }: BankEmailSettingsFormProps) {
  const queryClient = useQueryClient();
  const [bankName, setBankName] = useState(savedSettings.bank_name ?? "");
  // Always one row to type into, even before any address is saved.
  const [recipientEmails, setRecipientEmails] = useState(() =>
    savedSettings.recipient_emails.length > 0 ? savedSettings.recipient_emails : [""],
  );
  const [templates, setTemplates] = useState<Record<TemplateField, string>>({
    email_subject_template: savedSettings.email_subject_template,
    email_body_template: savedSettings.email_body_template,
  });
  const [savedMessage, setSavedMessage] = useState("");

  // Where the cursor last was in the subject or the message: a placeholder is inserted there.
  const lastCursor = useRef<{ field: TemplateField; start: number; end: number }>({
    field: "email_body_template",
    start: savedSettings.email_body_template.length,
    end: savedSettings.email_body_template.length,
  });

  const saveMutation = useMutation({
    mutationFn: () =>
      api.put<{ settings: BankEmailSettings }>(clientApiPath(clientId, "bank-email-settings"), {
        bank_name: bankName,
        recipient_emails: recipientEmails,
        ...templates,
      }),
    onMutate: () => setSavedMessage(""),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.allOfClient(clientId) });
      setRecipientEmails(result.data.settings.recipient_emails.length > 0 ? result.data.settings.recipient_emails : [""]);
      setSavedMessage(result.message);
    },
  });

  const fieldErrors = fieldErrorsOf(saveMutation.error);

  function rememberCursor(field: TemplateField) {
    return (selectEvent: SyntheticEvent<HTMLInputElement | HTMLTextAreaElement>) => {
      const { selectionStart, selectionEnd, value } = selectEvent.currentTarget;
      lastCursor.current = { field, start: selectionStart ?? value.length, end: selectionEnd ?? value.length };
    };
  }

  function insertPlaceholder(placeholder: string) {
    const { field, start, end } = lastCursor.current;
    const currentText = templates[field];
    setTemplates({ ...templates, [field]: currentText.slice(0, start) + placeholder + currentText.slice(end) });
    lastCursor.current = { field, start: start + placeholder.length, end: start + placeholder.length };
  }

  function changeRecipientEmail(changedIndex: number, emailAddress: string) {
    setRecipientEmails(recipientEmails.map((recipientEmail, emailIndex) => (emailIndex === changedIndex ? emailAddress : recipientEmail)));
  }

  function removeRecipientEmail(removedIndex: number) {
    const remainingEmails = recipientEmails.filter((_recipientEmail, emailIndex) => emailIndex !== removedIndex);
    setRecipientEmails(remainingEmails.length > 0 ? remainingEmails : [""]);
  }

  function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    saveMutation.mutate();
  }

  return (
    <form onSubmit={handleSubmit} className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_18rem]">
      <div className="space-y-6">
        {savedMessage ? <Notice tone="success">{savedMessage}</Notice> : null}
        {saveMutation.isError && Object.keys(fieldErrors).length === 0 ? (
          <Notice tone="refusal">{errorMessage(saveMutation.error)}</Notice>
        ) : null}

        <Panel className="space-y-5 p-6">
          <h2 className="font-serif text-lg font-semibold text-ink">Receivers</h2>
          <TextField
            label="Bank name"
            name="bank_name"
            maxLength={150}
            disabled={isReadOnly}
            value={bankName}
            error={fieldErrors.bank_name}
            onChange={(changeEvent) => setBankName(changeEvent.target.value)}
          />

          <fieldset>
            <legend className="mb-1 block text-sm font-medium text-ink">Bank email addresses</legend>
            <ul className="space-y-2">
              {recipientEmails.map((recipientEmail, emailIndex) => (
                <li key={emailIndex} className="flex gap-2">
                  <input
                    type="email"
                    aria-label={`Bank email address ${emailIndex + 1}`}
                    maxLength={190}
                    disabled={isReadOnly}
                    className={`${INPUT_CLASSES} ${fieldErrors.recipient_emails ? "border-refusal" : ""}`}
                    value={recipientEmail}
                    onChange={(changeEvent) => changeRecipientEmail(emailIndex, changeEvent.target.value)}
                  />
                  <Button
                    aria-label={`Remove bank email address ${emailIndex + 1}`}
                    disabled={isReadOnly || (recipientEmails.length === 1 && recipientEmail === "")}
                    onClick={() => removeRecipientEmail(emailIndex)}
                  >
                    Remove
                  </Button>
                </li>
              ))}
            </ul>
            {fieldErrors.recipient_emails ? <p className="mt-1 text-sm text-refusal">{fieldErrors.recipient_emails}</p> : null}
            <Button className="mt-3" disabled={isReadOnly || recipientEmails.length >= 10} onClick={() => setRecipientEmails([...recipientEmails, ""])}>
              Add another address
            </Button>
          </fieldset>
        </Panel>

        <Panel className="space-y-5 p-6">
          <h2 className="font-serif text-lg font-semibold text-ink">Email template</h2>
          <TextField
            label="Subject"
            name="email_subject_template"
            required
            maxLength={200}
            disabled={isReadOnly}
            value={templates.email_subject_template}
            error={fieldErrors.email_subject_template}
            onChange={(changeEvent) => setTemplates({ ...templates, email_subject_template: changeEvent.target.value })}
            onSelect={rememberCursor("email_subject_template")}
          />
          <div>
            <label htmlFor="field-email_body_template" className="mb-1 block text-sm font-medium text-ink">
              Message
            </label>
            <textarea
              id="field-email_body_template"
              name="email_body_template"
              required
              rows={12}
              maxLength={5000}
              disabled={isReadOnly}
              aria-invalid={fieldErrors.email_body_template ? true : undefined}
              className={`${INPUT_CLASSES} leading-relaxed ${fieldErrors.email_body_template ? "border-refusal" : ""}`}
              value={templates.email_body_template}
              onChange={(changeEvent) => setTemplates({ ...templates, email_body_template: changeEvent.target.value })}
              onSelect={rememberCursor("email_body_template")}
            />
            {fieldErrors.email_body_template ? <p className="mt-1 text-sm text-refusal">{fieldErrors.email_body_template}</p> : null}
          </div>
        </Panel>

        <div className="flex justify-end">
          <Button type="submit" variant="primary" disabled={isReadOnly || saveMutation.isPending}>
            {saveMutation.isPending ? "Saving…" : "Save bank settings"}
          </Button>
        </div>
      </div>

      <Panel className="h-fit p-5">
        <h2 className="mb-3 font-serif text-lg font-semibold text-ink">Insert placeholder</h2>
        <ul className="grid gap-2">
          {Object.entries(placeholders).map(([placeholder, meaning]) => (
            <li key={placeholder}>
              <button
                type="button"
                disabled={isReadOnly}
                className="flex w-full items-center justify-between gap-3 rounded-lg border border-rule px-3 py-2 text-left text-sm hover:border-brand hover:bg-brand-tint disabled:pointer-events-none disabled:opacity-50"
                onClick={() => insertPlaceholder(placeholder)}
              >
                <span className="font-medium text-ink">{meaning}</span>
                <span className="text-xs text-ink-soft">{placeholder}</span>
              </button>
            </li>
          ))}
        </ul>
      </Panel>
    </form>
  );
}
