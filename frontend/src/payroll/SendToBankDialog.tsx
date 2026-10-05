// The last look before a payroll run is emailed to the client's bank: who receives it, the words as
// filled in from the client's template (still editable for this one email), and the attached bank file.

import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQueryClient } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { api, errorMessage } from "../lib/apiClient";
import { clientApiPath, queryKeys } from "../lib/queryKeys";
import { Button, Dialog, INPUT_CLASSES, LoadingState, Notice, TextField } from "../shared/ui";
import type { RunBankEmail } from "../types";

interface SendToBankDialogProps {
  isOpen: boolean;
  clientId: number;
  payrollRunId: number;
  /** Undefined while it is still being fetched. */
  bankEmail: RunBankEmail | undefined;
  onClose: () => void;
  onSent: (message: string) => void;
}

export function SendToBankDialog({ isOpen, clientId, payrollRunId, bankEmail, onClose, onSent }: SendToBankDialogProps) {
  return (
    <Dialog isOpen={isOpen} title="Send payroll to the bank" onClose={onClose} widthClassName="max-w-2xl">
      {bankEmail ? (
        <SendToBankForm clientId={clientId} payrollRunId={payrollRunId} bankEmail={bankEmail} onClose={onClose} onSent={onSent} />
      ) : (
        <LoadingState />
      )}
    </Dialog>
  );
}

type SendToBankFormProps = Omit<SendToBankDialogProps, "isOpen" | "bankEmail"> & { bankEmail: RunBankEmail };

function SendToBankForm({ clientId, payrollRunId, bankEmail, onClose, onSent }: SendToBankFormProps) {
  const queryClient = useQueryClient();
  const [emailSubject, setEmailSubject] = useState(bankEmail.email_subject);
  const [emailBody, setEmailBody] = useState(bankEmail.email_body);

  const sendMutation = useMutation({
    mutationFn: () =>
      api.post<{ bank_email: RunBankEmail }>(clientApiPath(clientId, `payroll-runs/${payrollRunId}/bank-email`), {
        email_subject: emailSubject,
        email_body: emailBody,
      }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.runBankEmail(clientId, payrollRunId) });
      onSent(result.message);
    },
  });

  const hasRecipients = bankEmail.recipient_emails.length > 0;
  const employeesMissingAccountDetails = bankEmail.employees_missing_account_details;
  const canSend = hasRecipients && bankEmail.is_mail_configured && employeesMissingAccountDetails.length === 0;
  const bankSettingsAddress = `/clients/${clientId}/bank`;

  function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    sendMutation.mutate();
  }

  return (
    <form onSubmit={handleSubmit} className="space-y-4">
      {sendMutation.isError ? <Notice tone="refusal">{errorMessage(sendMutation.error)}</Notice> : null}
      {bankEmail.is_mail_configured ? null : <Notice tone="attention">Email is not set up on this server yet.</Notice>}
      {employeesMissingAccountDetails.length > 0 ? (
        <Notice tone="attention">
          No bank account recorded for {employeesMissingAccountDetails.join(", ")}.{" "}
          <Link to={`/clients/${clientId}/employees`} className="font-medium underline underline-offset-2">
            Open employees
          </Link>
        </Notice>
      ) : null}

      <div>
        <div className="mb-1 flex items-baseline justify-between">
          <span className="text-sm font-medium text-ink">To</span>
          <Link to={bankSettingsAddress} className="text-sm font-medium text-brand underline-offset-2 hover:underline">
            Edit receivers
          </Link>
        </div>
        {hasRecipients ? (
          <ul className="flex flex-wrap gap-2">
            {bankEmail.recipient_emails.map((recipientEmail) => (
              <li key={recipientEmail} className="rounded-full bg-brand-tint px-3 py-1 text-sm font-medium text-brand-deep">
                {recipientEmail}
              </li>
            ))}
          </ul>
        ) : (
          <Notice tone="attention">This client has no bank email address yet.</Notice>
        )}
      </div>

      <TextField
        label="Subject"
        name="email_subject"
        required
        maxLength={200}
        value={emailSubject}
        onChange={(changeEvent) => setEmailSubject(changeEvent.target.value)}
      />

      <div>
        <label htmlFor="field-email_body" className="mb-1 block text-sm font-medium text-ink">
          Message
        </label>
        <textarea
          id="field-email_body"
          name="email_body"
          required
          rows={11}
          maxLength={5000}
          className={`${INPUT_CLASSES} leading-relaxed`}
          value={emailBody}
          onChange={(changeEvent) => setEmailBody(changeEvent.target.value)}
        />
      </div>

      <p className="text-sm text-ink-soft">
        Attached:{" "}
        {/* A plain link: the browser downloads the workbook with the session cookie. */}
        <a
          href={clientApiPath(clientId, `payroll-runs/${payrollRunId}/bank-file`)}
          className="font-medium text-brand underline underline-offset-2"
        >
          {bankEmail.attachment_filename}
        </a>
      </p>

      <div className="flex justify-end gap-2 pt-2">
        <Button onClick={onClose} disabled={sendMutation.isPending}>
          Cancel
        </Button>
        <Button type="submit" variant="primary" disabled={!canSend || sendMutation.isPending}>
          {sendMutation.isPending ? "Sending…" : "Send to bank"}
        </Button>
      </div>
    </form>
  );
}
