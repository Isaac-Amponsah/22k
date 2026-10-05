// The search box in the top bar: type part of a client's name and open its books.

import { useState } from "react";
import type { FormEvent, KeyboardEvent } from "react";
import { useNavigate } from "react-router-dom";
import { clientBinder } from "../lib/clientBinder";
import { Icon } from "../shared/icons";
import type { Client } from "../types";
import { useClientsQuery } from "./useClientsQuery";

const MOST_MATCHES_SHOWN = 8;

export function ClientJumpSearch() {
  const navigate = useNavigate();
  const clientsQuery = useClientsQuery();
  const [searchText, setSearchText] = useState("");
  const [isListOpen, setIsListOpen] = useState(false);

  const wantedText = searchText.trim().toLowerCase();
  const matchingClients =
    wantedText === ""
      ? []
      : (clientsQuery.data ?? []).filter((client) => client.client_name.toLowerCase().includes(wantedText)).slice(0, MOST_MATCHES_SHOWN);

  function openClient(client: Client) {
    setSearchText("");
    setIsListOpen(false);
    navigate(`/clients/${client.client_id}`);
  }

  function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    if (matchingClients.length > 0) {
      openClient(matchingClients[0]);
    }
  }

  function handleKeyDown(keyEvent: KeyboardEvent) {
    if (keyEvent.key === "Escape") {
      setSearchText("");
      setIsListOpen(false);
    }
  }

  return (
    <form role="search" onSubmit={handleSubmit} className="relative w-full max-w-md">
      <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-ink-soft">
        <Icon name="search" className="size-4" />
      </span>
      <input
        type="search"
        aria-label="Find a client"
        placeholder="Find a client"
        autoComplete="off"
        className="w-full rounded-lg border border-rule bg-paper py-2 pr-3 pl-9 text-sm text-ink placeholder:text-ink-soft/70 focus:bg-surface"
        value={searchText}
        onChange={(changeEvent) => {
          setSearchText(changeEvent.target.value);
          setIsListOpen(true);
        }}
        onFocus={() => setIsListOpen(true)}
        onBlur={() => setIsListOpen(false)}
        onKeyDown={handleKeyDown}
      />

      {isListOpen && wantedText !== "" ? (
        <ul className="absolute inset-x-0 top-full z-30 mt-2 overflow-hidden rounded-lg border border-rule bg-surface py-1 shadow-card">
          {matchingClients.length === 0 ? (
            <li className="px-3 py-2 text-sm text-ink-soft">No client matches.</li>
          ) : (
            matchingClients.map((client) => (
              <li key={client.client_id}>
                <button
                  type="button"
                  className="flex w-full items-center gap-3 px-3 py-2 text-left text-sm text-ink hover:bg-brand-tint"
                  // The input's blur would close the list before a click lands; keep focus where it is.
                  onMouseDown={(mouseEvent) => mouseEvent.preventDefault()}
                  onClick={() => openClient(client)}
                >
                  <span
                    aria-hidden="true"
                    className="h-5 w-1.5 shrink-0 rounded-sm"
                    style={{ backgroundColor: clientBinder(client.client_id).spine }}
                  />
                  <span className="truncate font-medium">{client.client_name}</span>
                  {client.is_archived ? <span className="ml-auto text-xs text-ink-soft">Archived</span> : null}
                </button>
              </li>
            ))
          )}
        </ul>
      ) : null}
    </form>
  );
}
