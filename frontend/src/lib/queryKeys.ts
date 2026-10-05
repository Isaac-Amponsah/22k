// Cache keys. Everything that belongs to a client is keyed under that client's id first, so what was
// fetched for one client can never be shown while another client's books are open.

export const queryKeys = {
  clients: () => ["clients"] as const,
  rateSets: () => ["payroll-rates"] as const,

  allOfClient: (clientId: number) => ["client", clientId] as const,
  employees: (clientId: number) => ["client", clientId, "employees"] as const,
  salaries: (clientId: number) => ["client", clientId, "salaries"] as const,
  payrollRuns: (clientId: number) => ["client", clientId, "payroll-runs"] as const,
  payrollRun: (clientId: number, payrollRunId: number) => ["client", clientId, "payroll-runs", payrollRunId] as const,
};

/** The API address of something inside one client's books. */
export function clientApiPath(clientId: number, pathInsideClient: string): string {
  return `/api/clients/${clientId}/${pathInsideClient}`;
}
