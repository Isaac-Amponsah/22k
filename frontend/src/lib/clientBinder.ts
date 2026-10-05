// Each client gets a binder colour, like the spine of the paper file an accountant would pull off the
// shelf. Every screen inside a client's books wears it, so two browser tabs open on two clients never
// look alike. The colour follows the client's id, so it is the same on every visit.

const BINDER_COLOURS = [
  { spine: "#1f4d3a", tint: "#e6eee9" }, // ledger green
  { spine: "#8a3b12", tint: "#f6e9e1" }, // oxblood
  { spine: "#1e4b7a", tint: "#e4edf6" }, // navy
  { spine: "#6b4a8c", tint: "#eee8f4" }, // plum
  { spine: "#8b6a08", tint: "#f6f0d9" }, // mustard
  { spine: "#0f6268", tint: "#e0f0f0" }, // teal
  { spine: "#9b2c4a", tint: "#f7e6eb" }, // claret
  { spine: "#46522a", tint: "#ecefe2" }, // olive
];

export interface ClientBinder {
  spine: string;
  tint: string;
}

export function clientBinder(clientId: number): ClientBinder {
  return BINDER_COLOURS[clientId % BINDER_COLOURS.length];
}

/** Every binder colour, in order — for drawing the whole shelf. */
export function allClientBinders(): readonly ClientBinder[] {
  return BINDER_COLOURS;
}
