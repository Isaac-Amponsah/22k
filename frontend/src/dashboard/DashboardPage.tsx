// The first screen after sign-in: one month across every client. Cards give the month's figures, each
// client's binder stands on the shelf under the stage its payroll run has reached, the to-do card
// lists whoever is not paid yet, and the ledger below gives every client's figures.

import type { ReactNode } from "react";
import { keepPreviousData, useQuery } from "@tanstack/react-query";
import { Link, useSearchParams } from "react-router-dom";
import { api, errorMessage } from "../lib/apiClient";
import { clientBinder } from "../lib/clientBinder";
import { currentMonthInputValue, formatMoney, formatPayMonth, shiftMonthInputValue } from "../lib/formatting";
import { queryKeys } from "../lib/queryKeys";
import { Icon } from "../shared/icons";
import type { IconName } from "../shared/icons";
import { EmptyState, LoadingState, Notice, Panel, RunStatusBadge } from "../shared/ui";
import type { MonthOverview, MonthOverviewClient, PayrollRunStatus } from "../types";

type MonthStage = "not_started" | PayrollRunStatus;

/** The stages a client's month moves through, in order, and what moves it on from each. */
const MONTH_STAGES: { stage: MonthStage; label: string; nextStep: string | null }[] = [
  { stage: "not_started", label: "Not started", nextStep: "Start run" },
  { stage: "draft", label: "Draft", nextStep: "Review draft" },
  { stage: "finalised", label: "Finalised", nextStep: "Mark paid" },
  { stage: "paid", label: "Paid", nextStep: null },
];

function monthStageOf(client: MonthOverviewClient): MonthStage {
  return client.period_run?.status ?? "not_started";
}

/** Where a client's month is worked on: its run when there is one, otherwise its list of runs. */
function monthAddressOf(client: MonthOverviewClient): string {
  const payrollAddress = `/clients/${client.client_id}/payroll`;
  return client.period_run ? `${payrollAddress}/${client.period_run.payroll_run_id}` : payrollAddress;
}

const MONTH_STEP_BUTTON_CLASSES =
  "flex size-9 items-center justify-center rounded-md text-ink-soft hover:bg-brand-tint hover:text-brand-deep disabled:pointer-events-none disabled:opacity-40";

export function DashboardPage() {
  const [searchParams, setSearchParams] = useSearchParams();
  const monthInAddress = searchParams.get("month") ?? "";

  const overviewQuery = useQuery({
    queryKey: queryKeys.monthOverview(monthInAddress),
    queryFn: async () => (await api.get<MonthOverview>(`/api/dashboard?month=${encodeURIComponent(monthInAddress)}`)).data,
    // Stepping to another month keeps the last one on screen until the next arrives.
    placeholderData: keepPreviousData,
  });

  if (overviewQuery.isPending) {
    return <LoadingState />;
  }
  if (overviewQuery.isError) {
    return (
      <main className="mx-auto max-w-7xl px-4 py-8 lg:px-8">
        <Notice tone="refusal">{errorMessage(overviewQuery.error)}</Notice>
      </main>
    );
  }

  const overview = overviewQuery.data;
  const shownMonth = overview.pay_period.slice(0, 7);
  const shownMonthName = formatPayMonth(overview.pay_period);
  const showMonth = (monthInputValue: string) => setSearchParams({ month: monthInputValue });

  return (
    <main className="mx-auto max-w-7xl px-4 py-8 lg:px-8">
      <div className="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 className="font-serif text-2xl font-semibold text-ink">Dashboard</h1>

        <div className="flex items-center gap-2">
          {shownMonth === currentMonthInputValue() ? null : (
            <button
              type="button"
              className="rounded-lg px-3 py-2 text-sm font-medium text-brand hover:bg-brand-tint"
              onClick={() => setSearchParams({})}
            >
              This month
            </button>
          )}
          <div className="flex items-center gap-1 rounded-lg border border-rule bg-surface p-1 shadow-card">
            <button
              type="button"
              aria-label="Previous month"
              className={MONTH_STEP_BUTTON_CLASSES}
              onClick={() => showMonth(shiftMonthInputValue(shownMonth, -1))}
            >
              <Icon name="chevron-left" className="size-4" />
            </button>
            <span aria-live="polite" className="min-w-36 text-center text-sm font-semibold text-ink">
              {shownMonthName}
            </span>
            <button
              type="button"
              aria-label="Next month"
              className={MONTH_STEP_BUTTON_CLASSES}
              disabled={shownMonth >= overview.latest_month}
              onClick={() => showMonth(shiftMonthInputValue(shownMonth, 1))}
            >
              <Icon name="chevron-right" className="size-4" />
            </button>
          </div>
        </div>
      </div>

      <MonthFigureCards overview={overview} />

      <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
        <Panel className="p-6">
          <h2 className="mb-6 font-serif text-lg font-semibold text-ink">Payroll runs</h2>
          {overview.clients.length === 0 ? <EmptyState>No clients on this month.</EmptyState> : <MonthShelf clients={overview.clients} />}
        </Panel>

        <div className="grid content-start gap-6">
          <MonthToDoCard clients={overview.clients} shownMonthName={shownMonthName} />
          <div className="grid grid-cols-2 gap-4">
            <QuickActionCard to="/clients?add" icon="plus" label="Add client" />
            <QuickActionCard to="/payroll-rates" icon="rates" label="Payroll rates" />
          </div>
        </div>
      </div>

      {overview.clients.length > 0 ? <MonthLedger clients={overview.clients} /> : null}
    </main>
  );
}

/** The month in four cards: what was paid out, what is owed to the state, and to how many people. */
function MonthFigureCards({ overview }: { overview: MonthOverview }) {
  const activeClients = overview.clients.filter((client) => !client.is_archived);
  const paidClientCount = activeClients.filter((client) => monthStageOf(client) === "paid").length;
  const paidShare = activeClients.length === 0 ? 0 : paidClientCount / activeClients.length;

  const monthRuns = overview.clients.flatMap((client) => (client.period_run ? [client.period_run] : []));
  const employeeSsnit = monthRuns.reduce((runningTotal, run) => runningTotal + Number(run.total_employee_ssnit), 0);
  const employerSsnit = monthRuns.reduce((runningTotal, run) => runningTotal + Number(run.total_employer_ssnit), 0);
  const activeEmployeeCount = activeClients.reduce((runningTotal, client) => runningTotal + client.active_employee_count, 0);

  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <section className="relative overflow-hidden rounded-xl bg-brand p-5 text-white shadow-card">
        <span aria-hidden="true" className="absolute -top-10 -right-10 size-36 rounded-full bg-white/10" />
        <span aria-hidden="true" className="absolute -right-4 -bottom-16 size-36 rounded-full bg-white/5" />
        <div className="relative">
          <div className="flex items-center justify-between">
            <h2 className="text-sm font-medium text-white/80">Take home</h2>
            <span className="flex size-10 items-center justify-center rounded-lg bg-white/15">
              <Icon name="wallet" />
            </span>
          </div>
          <p className="mt-3 text-2xl font-semibold tabular-nums">
            <span className="mr-1.5 text-sm font-medium text-white/70">GHS</span>
            {formatMoney(overview.totals.total_net_pay)}
          </p>
          <div
            role="progressbar"
            aria-label="Clients paid"
            aria-valuemin={0}
            aria-valuemax={activeClients.length}
            aria-valuenow={paidClientCount}
            className="mt-4 h-1.5 overflow-hidden rounded-full bg-white/20"
          >
            <div className="h-full rounded-full bg-white" style={{ width: `${paidShare * 100}%` }} />
          </div>
          <p className="mt-2 text-sm text-white/80">
            {paidClientCount} of {activeClients.length} clients paid
          </p>
        </div>
      </section>

      <FigureCard
        label="PAYE"
        icon="receipt"
        iconClassName="bg-attention-tint text-attention"
        currency="GHS"
        footnote={`Across ${monthRuns.length} ${monthRuns.length === 1 ? "run" : "runs"}`}
      >
        {formatMoney(overview.totals.total_paye)}
      </FigureCard>
      <FigureCard
        label="SSNIT"
        icon="shield"
        iconClassName="bg-brand-tint text-brand"
        currency="GHS"
        footnote={`Employee ${formatMoney(employeeSsnit)}, employer ${formatMoney(employerSsnit)}`}
      >
        {formatMoney(overview.totals.total_ssnit)}
      </FigureCard>
      <FigureCard
        label="Employees paid"
        icon="people"
        iconClassName="bg-success-tint text-success"
        footnote={`Of ${activeEmployeeCount} active`}
      >
        {overview.totals.employees_paid}
      </FigureCard>
    </div>
  );
}

interface FigureCardProps {
  label: string;
  icon: IconName;
  iconClassName: string;
  currency?: string;
  footnote: string;
  children: ReactNode;
}

function FigureCard({ label, icon, iconClassName, currency, footnote, children }: FigureCardProps) {
  return (
    <Panel className="p-5">
      <div className="flex items-center justify-between">
        <h2 className="text-sm font-medium text-ink-soft">{label}</h2>
        <span className={`flex size-10 items-center justify-center rounded-lg ${iconClassName}`}>
          <Icon name={icon} />
        </span>
      </div>
      <p className="mt-3 text-2xl font-semibold text-ink tabular-nums">
        {currency ? <span className="mr-1.5 text-sm font-medium text-ink-soft">{currency}</span> : null}
        {children}
      </p>
      <p className="mt-4 border-t border-rule pt-3 text-sm text-ink-soft tabular-nums">{footnote}</p>
    </Panel>
  );
}

function QuickActionCard({ to, icon, label }: { to: string; icon: IconName; label: string }) {
  return (
    <Link
      to={to}
      className="group flex flex-col gap-3 rounded-xl border border-rule bg-surface p-4 shadow-card transition-colors hover:border-brand"
    >
      <span className="flex size-10 items-center justify-center rounded-lg bg-brand-tint text-brand transition-colors group-hover:bg-brand group-hover:text-white">
        <Icon name={icon} />
      </span>
      <span className="text-sm font-semibold text-ink">{label}</span>
    </Link>
  );
}

/** Every client not yet paid for the month, each with the step that moves it on. */
function MonthToDoCard({ clients, shownMonthName }: { clients: MonthOverviewClient[]; shownMonthName: string }) {
  const clientsToDo = MONTH_STAGES.flatMap(({ stage, nextStep }) =>
    nextStep === null
      ? []
      : clients.filter((client) => !client.is_archived && monthStageOf(client) === stage).map((client) => ({ client, nextStep })),
  );

  return (
    <Panel>
      <h2 className="flex items-center justify-between px-5 pt-5 pb-3 font-serif text-lg font-semibold text-ink">
        To do
        <span className="rounded-full bg-brand-tint px-2.5 py-0.5 font-sans text-xs font-semibold text-brand-deep tabular-nums">
          {clientsToDo.length}
        </span>
      </h2>
      {clientsToDo.length === 0 ? (
        <p className="px-5 pb-5 text-sm text-ink-soft">Nothing left for {shownMonthName}.</p>
      ) : (
        <ul className="max-h-72 overflow-y-auto pb-2">
          {clientsToDo.map(({ client, nextStep }) => (
            <li key={client.client_id}>
              <Link to={monthAddressOf(client)} className="group flex items-center gap-3 px-5 py-2.5 hover:bg-paper">
                <span
                  aria-hidden="true"
                  className="h-6 w-1.5 shrink-0 rounded-sm"
                  style={{ backgroundColor: clientBinder(client.client_id).spine }}
                />
                <span className="min-w-0 flex-1 truncate text-sm font-medium text-ink">{client.client_name}</span>
                <span className="flex shrink-0 items-center gap-1 text-sm font-medium text-brand">
                  {nextStep}
                  <Icon name="arrow-right" className="size-4 transition-transform group-hover:translate-x-0.5" />
                </span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </Panel>
  );
}

/** One shelf per stage, each as wide as the binders standing on it. */
function MonthShelf({ clients }: { clients: MonthOverviewClient[] }) {
  return (
    <div className="flex flex-wrap gap-x-5 gap-y-8">
      {MONTH_STAGES.map(({ stage, label }) => {
        const clientsAtStage = clients.filter((client) => monthStageOf(client) === stage);
        return (
          <section
            key={stage}
            className="min-w-0"
            style={{ flexGrow: Math.max(clientsAtStage.length, 1), flexBasis: `${Math.max(clientsAtStage.length, 3) * 2.25}rem` }}
          >
            <h3 className="mb-3 flex items-baseline gap-2">
              <span className="text-2xl font-semibold text-ink tabular-nums">{clientsAtStage.length}</span>
              <span className="text-sm font-medium text-ink-soft">{label}</span>
            </h3>
            <ul className="flex h-56 items-end gap-1.5">
              {clientsAtStage.map((client) => (
                <ShelfBinder key={client.client_id} client={client} stageLabel={label} />
              ))}
            </ul>
            <div className="h-2 rounded-t-sm bg-ink" />
          </section>
        );
      })}
    </div>
  );
}

function ShelfBinder({ client, stageLabel }: { client: MonthOverviewClient; stageLabel: string }) {
  const binder = clientBinder(client.client_id);

  return (
    <li className="h-full max-w-12 min-w-6 flex-1">
      <Link
        to={monthAddressOf(client)}
        title={client.client_name}
        aria-label={`${client.client_name}: ${stageLabel}`}
        className="binder-spine binder-link relative block h-full rounded-t-md"
        style={{ backgroundColor: binder.spine }}
      >
        <span
          className="absolute inset-x-[16%] top-[8%] bottom-[26%] flex items-center justify-center rounded-sm py-1.5"
          style={{ backgroundColor: binder.tint, color: binder.spine }}
        >
          <span className="binder-label text-xs font-semibold">{client.client_name}</span>
        </span>
        {/* The finger hole near its foot. */}
        <span className="absolute bottom-[9%] left-1/2 size-3 -translate-x-1/2 rounded-full bg-black/25" />
      </Link>
    </li>
  );
}

function MonthLedger({ clients }: { clients: MonthOverviewClient[] }) {
  return (
    <Panel className="mt-6 overflow-hidden">
      <h2 className="px-6 pt-5 pb-4 font-serif text-lg font-semibold text-ink">All clients</h2>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead className="border-y border-rule bg-paper/60 text-left text-ink-soft">
            <tr>
              <th className="px-6 py-3 font-medium">Client</th>
              <th className="px-4 py-3 font-medium">Status</th>
              <th className="figure px-4 py-3 font-medium">Employees</th>
              <th className="figure px-4 py-3 font-medium">PAYE</th>
              <th className="figure px-4 py-3 font-medium">SSNIT</th>
              <th className="figure px-6 py-3 font-medium">Take home</th>
            </tr>
          </thead>
          <tbody>
            {clients.map((client) => {
              const run = client.period_run;
              return (
                <tr key={client.client_id} className="border-b border-rule/60 last:border-0 hover:bg-paper/50">
                  <td className="px-6 py-3">
                    <Link to={monthAddressOf(client)} className="group flex items-center gap-3">
                      <span
                        aria-hidden="true"
                        className="h-6 w-1.5 shrink-0 rounded-sm"
                        style={{ backgroundColor: clientBinder(client.client_id).spine }}
                      />
                      <span className="font-medium text-ink underline-offset-2 group-hover:underline">{client.client_name}</span>
                      {client.is_archived ? <span className="text-xs text-ink-soft">Archived</span> : null}
                    </Link>
                  </td>
                  <td className="px-4 py-3">
                    {run ? <RunStatusBadge status={run.status} /> : <span className="text-ink-soft">Not started</span>}
                  </td>
                  <td className="figure px-4 py-3">{run ? run.employee_count : client.active_employee_count}</td>
                  <td className="figure px-4 py-3">{formatMoney(run?.total_paye)}</td>
                  <td className="figure px-4 py-3">
                    {formatMoney(run ? Number(run.total_employee_ssnit) + Number(run.total_employer_ssnit) : null)}
                  </td>
                  <td className="figure px-6 py-3 font-semibold">{formatMoney(run?.total_net_pay)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </Panel>
  );
}
