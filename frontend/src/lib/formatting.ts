const moneyFormat = new Intl.NumberFormat("en-GH", { minimumFractionDigits: 2, maximumFractionDigits: 2 });

/** 4000 or "4000.00" → "4,000.00". An empty value shows as a dash. */
export function formatMoney(amount: number | string | null | undefined): string {
  if (amount === null || amount === undefined || amount === "") {
    return "—";
  }
  return moneyFormat.format(Number(amount));
}

/** "5.50" → "5.5", "13.00" → "13". */
export function formatPercent(percent: number | string): string {
  return String(Number(percent));
}

/** "2026-10-01" → "October 2026". Parsed by hand so the browser's time zone cannot shift the month. */
export function formatPayMonth(payPeriod: string): string {
  const [year, month] = payPeriod.split("-").map(Number);
  return new Date(year, month - 1, 1).toLocaleDateString("en-GB", { month: "long", year: "numeric" });
}

/** "2026-10-05 14:02:11" or "2026-10-05" → "5 Oct 2026". */
export function formatDate(dateText: string | null | undefined): string {
  if (!dateText) {
    return "—";
  }
  const [year, month, day] = dateText.slice(0, 10).split("-").map(Number);
  return new Date(year, month - 1, day).toLocaleDateString("en-GB", { day: "numeric", month: "short", year: "numeric" });
}

/** This month as "YYYY-MM", the value a month input uses. */
export function currentMonthInputValue(): string {
  const today = new Date();
  return `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}`;
}

export function fullName(person: { first_name: string; last_name: string }): string {
  return `${person.first_name} ${person.last_name}`.trim();
}
