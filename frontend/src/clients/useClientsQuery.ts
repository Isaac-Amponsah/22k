import { useQuery } from "@tanstack/react-query";
import { api } from "../lib/apiClient";
import { queryKeys } from "../lib/queryKeys";
import type { Client } from "../types";

/** The signed-in accountant's clients, active first, then by name. */
export function useClientsQuery() {
  return useQuery({
    queryKey: queryKeys.clients(),
    queryFn: async () => (await api.get<{ clients: Client[] }>("/api/clients")).data.clients,
  });
}
