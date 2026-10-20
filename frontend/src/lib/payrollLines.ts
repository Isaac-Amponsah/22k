// Reading a payroll run's lines: the allowance and deduction columns a run shows, and figures added
// together in whole pesewas so a column's total is always the sum of its rows.

import type { PayItemKind, PayrollRunLine } from "../types";

export interface ItemColumn {
  itemName: string;
  /** Allowances only. */
  isTaxable: boolean;
}

/** One column per allowance or deduction named on any line, in the order they first appear. */
export function itemColumnsOf(lines: PayrollRunLine[], itemKind: PayItemKind): ItemColumn[] {
  const columnsByName = new Map<string, ItemColumn>();
  for (const line of lines) {
    for (const lineItem of line.items) {
      if (lineItem.item_kind === itemKind && !columnsByName.has(lineItem.item_name)) {
        columnsByName.set(lineItem.item_name, { itemName: lineItem.item_name, isTaxable: lineItem.is_taxable !== false });
      }
    }
  }
  return [...columnsByName.values()];
}

function toPesewas(amount: number | string): number {
  return Math.round(Number(amount) * 100);
}

/** What one line has on one allowance, deduction or relief. */
export function itemAmountOf(line: PayrollRunLine, itemKind: PayrollRunLine["items"][number]["item_kind"], itemName: string): number {
  return (
    line.items
      .filter((lineItem) => lineItem.item_kind === itemKind && lineItem.item_name === itemName)
      .reduce((totalPesewas, lineItem) => totalPesewas + toPesewas(lineItem.amount), 0) / 100
  );
}

/** Line figures added together. */
export function sumOf(...amounts: (number | string)[]): number {
  return amounts.reduce<number>((totalPesewas, amount) => totalPesewas + toPesewas(amount), 0) / 100;
}

/** a - b, to the pesewa. */
export function differenceOf(amount: number | string, lessAmount: number | string): number {
  return (toPesewas(amount) - toPesewas(lessAmount)) / 100;
}

/** A value worked out per line, totalled over the lines. */
export function totalOver(lines: PayrollRunLine[], valueOf: (line: PayrollRunLine) => number): number {
  return sumOf(...lines.map(valueOf));
}
