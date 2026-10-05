// Small building blocks shared by every page. No browser alert/confirm anywhere: ConfirmDialog instead.

import { useEffect, useRef } from "react";
import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode } from "react";
import type { PayrollRunStatus } from "../types";

type ButtonVariant = "primary" | "quiet" | "danger";

const BUTTON_VARIANT_CLASSES: Record<ButtonVariant, string> = {
  primary: "bg-ledger text-white hover:bg-ledger-deep border-ledger",
  quiet: "bg-surface text-ink hover:bg-ledger-tint border-rule",
  danger: "bg-surface text-refusal hover:bg-refusal-tint border-refusal/40",
};

interface ButtonProps extends ButtonHTMLAttributes<HTMLButtonElement> {
  variant?: ButtonVariant;
}

export function Button({ variant = "quiet", className = "", type = "button", ...buttonProps }: ButtonProps) {
  return (
    <button
      type={type}
      className={`inline-flex items-center justify-center gap-2 rounded-md border px-3.5 py-2 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 ${BUTTON_VARIANT_CLASSES[variant]} ${className}`}
      {...buttonProps}
    />
  );
}

export const INPUT_CLASSES =
  "w-full rounded-md border border-rule bg-surface px-3 py-2 text-sm text-ink placeholder:text-ink-soft/60 disabled:bg-paper";

interface TextFieldProps extends InputHTMLAttributes<HTMLInputElement> {
  label: string;
  error?: string;
  hint?: string;
}

export function TextField({ label, error, hint, id, name, className = "", ...inputProps }: TextFieldProps) {
  const fieldId = id ?? `field-${name}`;
  return (
    <div className={className}>
      <label htmlFor={fieldId} className="mb-1 block text-sm font-medium text-ink">
        {label}
      </label>
      <input
        id={fieldId}
        name={name}
        aria-invalid={error ? true : undefined}
        aria-describedby={error ? `${fieldId}-error` : undefined}
        className={`${INPUT_CLASSES} ${error ? "border-refusal" : ""}`}
        {...inputProps}
      />
      {error ? (
        <p id={`${fieldId}-error`} className="mt-1 text-sm text-refusal">
          {error}
        </p>
      ) : hint ? (
        <p className="mt-1 text-sm text-ink-soft">{hint}</p>
      ) : null}
    </div>
  );
}

type NoticeTone = "success" | "refusal" | "attention";

const NOTICE_TONE_CLASSES: Record<NoticeTone, string> = {
  success: "border-ledger/30 bg-ledger-tint text-ledger-deep",
  refusal: "border-refusal/30 bg-refusal-tint text-refusal",
  attention: "border-attention/30 bg-attention-tint text-attention",
};

export function Notice({ tone, children }: { tone: NoticeTone; children: ReactNode }) {
  return (
    <div role={tone === "refusal" ? "alert" : "status"} className={`rounded-md border px-4 py-3 text-sm ${NOTICE_TONE_CLASSES[tone]}`}>
      {children}
    </div>
  );
}

export function PageHeading({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 className="font-serif text-2xl font-semibold text-ink">{title}</h1>
        {description ? <p className="mt-1 max-w-2xl text-sm text-ink-soft">{description}</p> : null}
      </div>
      {actions ? <div className="screen-only flex flex-wrap gap-2">{actions}</div> : null}
    </div>
  );
}

export function Panel({ children, className = "" }: { children: ReactNode; className?: string }) {
  return <section className={`rounded-lg border border-rule bg-surface ${className}`}>{children}</section>;
}

export function EmptyState({ children }: { children: ReactNode }) {
  return <div className="px-6 py-12 text-center text-sm text-ink-soft">{children}</div>;
}

export function LoadingState() {
  return <div className="px-6 py-12 text-center text-sm text-ink-soft">Loading…</div>;
}

const RUN_STATUS_LABELS: Record<PayrollRunStatus, string> = { draft: "Draft", finalised: "Finalised", paid: "Paid" };
const RUN_STATUS_CLASSES: Record<PayrollRunStatus, string> = {
  draft: "bg-attention-tint text-attention",
  finalised: "bg-ledger-tint text-ledger-deep",
  paid: "bg-ledger text-white",
};

export function RunStatusBadge({ status }: { status: PayrollRunStatus }) {
  return (
    <span className={`inline-block rounded-full px-2.5 py-0.5 text-xs font-semibold ${RUN_STATUS_CLASSES[status]}`}>
      {RUN_STATUS_LABELS[status]}
    </span>
  );
}

interface DialogProps {
  isOpen: boolean;
  title: string;
  onClose: () => void;
  children: ReactNode;
  widthClassName?: string;
}

/** A modal on the native dialog element: focus is trapped and Escape closes it. */
export function Dialog({ isOpen, title, onClose, children, widthClassName = "max-w-lg" }: DialogProps) {
  const dialogElement = useRef<HTMLDialogElement>(null);

  useEffect(() => {
    const dialog = dialogElement.current;
    if (!dialog) {
      return;
    }
    if (isOpen && !dialog.open) {
      dialog.showModal();
    } else if (!isOpen && dialog.open) {
      dialog.close();
    }
  }, [isOpen]);

  return (
    <dialog
      ref={dialogElement}
      onClose={onClose}
      className={`m-auto w-[calc(100%-2rem)] ${widthClassName} rounded-lg border border-rule bg-surface p-0 text-ink shadow-xl`}
    >
      {isOpen ? (
        <div className="p-6">
          <h2 className="mb-4 font-serif text-xl font-semibold">{title}</h2>
          {children}
        </div>
      ) : null}
    </dialog>
  );
}

interface ConfirmDialogProps {
  isOpen: boolean;
  title: string;
  explanation: string;
  confirmLabel: string;
  isDangerous?: boolean;
  isWorking?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
}

export function ConfirmDialog({
  isOpen,
  title,
  explanation,
  confirmLabel,
  isDangerous = false,
  isWorking = false,
  onConfirm,
  onCancel,
}: ConfirmDialogProps) {
  return (
    <Dialog isOpen={isOpen} title={title} onClose={onCancel} widthClassName="max-w-md">
      <p className="text-sm text-ink-soft">{explanation}</p>
      <div className="mt-6 flex justify-end gap-2">
        <Button onClick={onCancel} disabled={isWorking}>
          Cancel
        </Button>
        <Button variant={isDangerous ? "danger" : "primary"} onClick={onConfirm} disabled={isWorking}>
          {isWorking ? "Working…" : confirmLabel}
        </Button>
      </div>
    </Dialog>
  );
}
