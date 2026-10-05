// One client's books. Everything rendered inside works on the client named in the address, and wears
// that client's binder colour so it is always plain whose payroll is on screen.

import { Link, NavLink, Outlet, useNavigate, useOutletContext, useParams } from "react-router-dom";
import { clientBinder } from "../lib/clientBinder";
import { EmptyState, LoadingState, Notice } from "../shared/ui";
import type { Client } from "../types";
import { useClientsQuery } from "./useClientsQuery";

interface ClientWorkspaceContext {
  client: Client;
}

/** The client whose books are open. Only usable on pages rendered inside ClientWorkspace. */
export function useClientInScope(): Client {
  return useOutletContext<ClientWorkspaceContext>().client;
}

const SECTIONS = [
  { path: "payroll", label: "Payroll" },
  { path: "salaries", label: "Salaries" },
  { path: "employees", label: "Employees" },
  { path: "bank", label: "Bank" },
];

export function ClientWorkspace() {
  const navigate = useNavigate();
  const clientIdInAddress = Number(useParams().clientId);

  const clientsQuery = useClientsQuery();

  if (clientsQuery.isPending) {
    return <LoadingState />;
  }

  const client = clientsQuery.data?.find((candidate) => candidate.client_id === clientIdInAddress);
  if (!client) {
    return (
      <EmptyState>
        This client is not in your list. <Link to="/clients" className="font-medium text-brand underline">Back to your clients</Link>
      </EmptyState>
    );
  }

  const binder = clientBinder(client.client_id);
  const otherClients = clientsQuery.data?.filter((candidate) => !candidate.is_archived || candidate.client_id === client.client_id) ?? [];

  return (
    <div style={{ borderTopColor: binder.spine }} className="border-t-[6px]">
      <div style={{ backgroundColor: binder.tint }} className="screen-only border-b border-rule">
        <div className="mx-auto max-w-7xl px-4 pt-5">
          <div className="flex flex-wrap items-end justify-between gap-4">
            <div>
              <p className="text-sm text-ink-soft">Client</p>
              <p style={{ color: binder.spine }} className="font-serif text-3xl font-semibold leading-tight">
                {client.client_name}
              </p>
            </div>
            <label className="text-sm text-ink-soft">
              <span className="mr-2">Open another client</span>
              <select
                className="rounded-md border border-rule bg-surface px-2 py-1.5 text-sm text-ink"
                value={client.client_id}
                onChange={(changeEvent) => navigate(`/clients/${changeEvent.target.value}`)}
              >
                {otherClients.map((candidate) => (
                  <option key={candidate.client_id} value={candidate.client_id}>
                    {candidate.client_name}
                  </option>
                ))}
              </select>
            </label>
          </div>

          <nav className="mt-4 flex gap-1" aria-label={`${client.client_name} sections`}>
            {SECTIONS.map((section) => (
              <NavLink
                key={section.path}
                to={section.path}
                style={({ isActive }) => (isActive ? { borderBottomColor: binder.spine, color: binder.spine } : undefined)}
                className={({ isActive }) =>
                  `border-b-[3px] px-4 py-2 text-sm font-semibold ${isActive ? "" : "border-transparent text-ink-soft hover:text-ink"}`
                }
              >
                {section.label}
              </NavLink>
            ))}
          </nav>
        </div>
      </div>

      {/* Keyed by client: moving to another client throws away every half-typed form and open dialog. */}
      <main key={client.client_id} className="mx-auto max-w-7xl px-4 py-8">
        {client.is_archived ? (
          <div className="mb-6">
            <Notice tone="attention">This client is archived. Its payroll can be read but not changed until it is restored.</Notice>
          </div>
        ) : null}
        <Outlet context={{ client } satisfies ClientWorkspaceContext} />
      </main>
    </div>
  );
}
