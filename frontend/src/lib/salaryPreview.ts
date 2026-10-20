// The pay a salary works out to, mirroring App\Services\PayrollCalculator on the server: every figure
// in whole pesewas, rounded once. A preview only — the server works the figures out again on save.

import type { PayItem, RateSet } from "../types";

/** One Salaries row as typed. pay_items is keyed by pay_item_id, reliefs by relief code (claims). */
export interface SalaryRowInput {
  basic_salary: string;
  tier3_percent: string;
  pay_items: Record<number, string>;
  reliefs: Record<string, number>;
}

export interface SalaryPreviewFigures {
  employee_ssnit: number;
  allowance: number;
  gross_pay: number;
  tier3_contribution: number;
  tax_relief: number;
  chargeable_income: number;
  paye: number;
  other_deductions: number;
  net_pay: number;
}

export type SalaryPreviewResult = { figures: SalaryPreviewFigures; error: null } | { figures: null; error: string };

/** The largest amount a salary column holds on the server (numeric(12,2)). */
const MAX_AMOUNT_PESEWAS = 999_999_999_999;

function toPesewas(amount: number): number {
  return Math.round(amount * 100);
}

function percentOf(pesewas: number, percent: number | string): number {
  return Math.round((pesewas * Number(percent)) / 100);
}

/** PAYE on a month's chargeable income; the last band (no width) taxes whatever is left. */
function payePesewas(chargeablePesewas: number, rateSet: RateSet): number {
  let remainingPesewas = Math.max(0, chargeablePesewas);
  let taxPesewas = 0;

  for (const band of rateSet.bands) {
    if (remainingPesewas <= 0) {
      break;
    }
    const bandWidthPesewas = band.band_width === null ? remainingPesewas : toPesewas(Number(band.band_width));
    const taxedInBandPesewas = Math.min(remainingPesewas, bandWidthPesewas);

    taxPesewas += (taxedInBandPesewas * Number(band.rate_percent)) / 100;
    remainingPesewas -= taxedInBandPesewas;
  }
  return Math.round(taxPesewas);
}

/** A typed amount as a number, or null when it is blank or not an amount of 0 or more. */
export function readAmount(typedAmount: string): number | null {
  const trimmed = typedAmount.trim().replaceAll(",", "");
  if (trimmed === "") {
    return null;
  }
  const amount = Number(trimmed);
  return Number.isFinite(amount) && amount >= 0 ? amount : null;
}

/** A blank amount counts as 0; null when it is not an amount of 0 or more. */
function readAmountOrZero(typedAmount: string | undefined): number | null {
  return (typedAmount ?? "").trim() === "" ? 0 : readAmount(typedAmount ?? "");
}

/** Everything but basic salary, read once, in pesewas. */
interface PayExtras {
  allowances: { payItemId: number; amountPesewas: number; isTaxable: boolean }[];
  deductionPesewas: number;
  tier3Percent: number;
  reliefs: Record<string, number>;
}

type PayExtrasResult = { extras: PayExtras; error: null } | { extras: null; error: string };

function readExtras(rowInput: SalaryRowInput, payItems: PayItem[]): PayExtrasResult {
  const tier3Percent = readAmountOrZero(rowInput.tier3_percent);
  if (tier3Percent === null || tier3Percent > 100) {
    return { extras: null, error: "Enter Tier 3 as a percentage from 0 to 100." };
  }

  const extras: PayExtras = { allowances: [], deductionPesewas: 0, tier3Percent, reliefs: rowInput.reliefs };
  for (const payItem of payItems) {
    const amount = readAmountOrZero(rowInput.pay_items[payItem.pay_item_id]);
    if (amount === null) {
      return { extras: null, error: `Enter ${payItem.item_name} as an amount of 0 or more.` };
    }
    const amountPesewas = toPesewas(amount);
    if (payItem.item_kind === "allowance") {
      extras.allowances.push({ payItemId: payItem.pay_item_id, amountPesewas, isTaxable: payItem.is_taxable });
    } else {
      extras.deductionPesewas += amountPesewas;
    }
  }
  return { extras, error: null };
}

interface PayPesewas {
  employeeSsnit: number;
  allowance: number;
  tier3: number;
  taxRelief: number;
  chargeable: number;
  paye: number;
  netPay: number;
}

type PayPesewasResult = { pay: PayPesewas; error: null } | { pay: null; error: string };

/**
 * One month's pay in pesewas. overrideAllowance replaces one allowance's amount, for working one out
 * from a take home. Net pay may come out below 0 here; previewSalary refuses that.
 */
function payPesewasOf(
  basicPesewas: number,
  extras: PayExtras,
  rateSet: RateSet,
  reliefLabels: Record<string, string>,
  overrideAllowance?: { payItemId: number; amountPesewas: number },
): PayPesewasResult {
  const employeeSsnitPesewas = percentOf(basicPesewas, rateSet.employee_ssnit_percent);
  const basicLessSsnitPesewas = basicPesewas - employeeSsnitPesewas;

  let allowancePesewas = 0;
  let taxableAllowancePesewas = 0;
  for (const allowance of extras.allowances) {
    const amountPesewas = overrideAllowance?.payItemId === allowance.payItemId ? overrideAllowance.amountPesewas : allowance.amountPesewas;
    allowancePesewas += amountPesewas;
    if (allowance.isTaxable) {
      taxableAllowancePesewas += amountPesewas;
    }
  }

  const assessablePesewas = basicLessSsnitPesewas + taxableAllowancePesewas;
  const tier3Pesewas = percentOf(basicPesewas, extras.tier3Percent);
  const tier3ReliefPesewas = Math.min(tier3Pesewas, percentOf(basicPesewas, rateSet.tier3_relief_cap_percent));

  let personalReliefPesewas = 0;
  for (const [reliefCode, claimCount] of Object.entries(extras.reliefs)) {
    if (claimCount <= 0) {
      continue;
    }
    const reliefRate = rateSet.reliefs[reliefCode];
    const reliefLabel = reliefLabels[reliefCode] ?? reliefCode;
    if (!reliefRate) {
      return { pay: null, error: `${reliefLabel} relief is not in the payroll rates in force.` };
    }
    if (claimCount > reliefRate.max_claims) {
      return { pay: null, error: `${reliefLabel} relief can be claimed at most ${reliefRate.max_claims} times.` };
    }
    personalReliefPesewas +=
      reliefRate.percent_of_assessable !== null
        ? percentOf(Math.max(0, assessablePesewas), reliefRate.percent_of_assessable)
        : Math.round((toPesewas(Number(reliefRate.annual_amount)) * claimCount) / 12);
  }

  const chargeablePesewas = Math.max(0, assessablePesewas - tier3ReliefPesewas - personalReliefPesewas);
  const taxPesewas = payePesewas(chargeablePesewas, rateSet);

  return {
    pay: {
      employeeSsnit: employeeSsnitPesewas,
      allowance: allowancePesewas,
      tier3: tier3Pesewas,
      taxRelief: tier3ReliefPesewas + personalReliefPesewas,
      chargeable: chargeablePesewas,
      paye: taxPesewas,
      netPay: basicLessSsnitPesewas + allowancePesewas - tier3Pesewas - taxPesewas - extras.deductionPesewas,
    },
    error: null,
  };
}

export function previewSalary(
  rowInput: SalaryRowInput,
  payItems: PayItem[],
  rateSet: RateSet,
  reliefLabels: Record<string, string>,
): SalaryPreviewResult | null {
  if (rowInput.basic_salary.trim() === "") {
    return null;
  }
  const basicSalary = readAmount(rowInput.basic_salary);
  if (basicSalary === null) {
    return { figures: null, error: "Enter a basic salary of 0 or more." };
  }
  const extrasResult = readExtras(rowInput, payItems);
  if (extrasResult.extras === null) {
    return { figures: null, error: extrasResult.error };
  }

  const basicPesewas = toPesewas(basicSalary);
  const payResult = payPesewasOf(basicPesewas, extrasResult.extras, rateSet, reliefLabels);
  if (payResult.pay === null) {
    return { figures: null, error: payResult.error };
  }
  const pay = payResult.pay;
  if (pay.netPay < 0) {
    return { figures: null, error: "Deductions come to more than this employee is paid." };
  }

  return {
    figures: {
      employee_ssnit: pay.employeeSsnit / 100,
      allowance: pay.allowance / 100,
      gross_pay: (basicPesewas + pay.allowance) / 100,
      tier3_contribution: pay.tier3 / 100,
      tax_relief: pay.taxRelief / 100,
      chargeable_income: pay.chargeable / 100,
      paye: pay.paye / 100,
      other_deductions: extrasResult.extras.deductionPesewas / 100,
      net_pay: pay.netPay / 100,
    },
    error: null,
  };
}

export type TakeHomeDerivation = { amount: number; takeHome: number; error: null } | { amount: null; takeHome: null; error: string };

/**
 * The amount (in pesewas) that makes take home reach the one asked for, where take home never falls as
 * that amount rises, so the search over whole pesewas is exact. Rounding can skip a pesewa of take home;
 * then the amount paying the nearest take home is chosen, the higher one on a tie so the employee is never short.
 * Null when even the largest amount a salary can hold does not reach it.
 */
function amountPesewasForTakeHome(takeHomePesewas: number, netPayPesewasFor: (amountPesewas: number) => number): number | null {
  // Bounded by what the server stores, which also keeps every figure an exact integer in a double.
  let highPesewas = MAX_AMOUNT_PESEWAS;
  if (netPayPesewasFor(highPesewas) < takeHomePesewas) {
    return null;
  }
  // Smallest amount whose take home reaches the one asked for.
  let lowPesewas = 0;
  while (lowPesewas < highPesewas) {
    const middlePesewas = Math.floor((lowPesewas + highPesewas) / 2);
    if (netPayPesewasFor(middlePesewas) >= takeHomePesewas) {
      highPesewas = middlePesewas;
    } else {
      lowPesewas = middlePesewas + 1;
    }
  }

  const reachedNetPesewas = netPayPesewasFor(lowPesewas);
  if (reachedNetPesewas !== takeHomePesewas && lowPesewas > 0) {
    const shortNetPesewas = netPayPesewasFor(lowPesewas - 1);
    if (takeHomePesewas - shortNetPesewas < reachedNetPesewas - takeHomePesewas) {
      return lowPesewas - 1;
    }
  }
  return lowPesewas;
}

function deriveForTakeHome(
  takeHomeInput: string,
  netPayPesewasFor: ((amountPesewas: number) => number) | null,
  belowMinimumReason: string,
  unreachableRefusal: string,
): TakeHomeDerivation {
  const takeHome = readAmount(takeHomeInput);
  if (takeHome === null) {
    return { amount: null, takeHome: null, error: "Enter a take home of 0 or more." };
  }
  if (netPayPesewasFor === null) {
    return { amount: null, takeHome: null, error: "Fix the figures in this row first." };
  }
  const takeHomePesewas = toPesewas(takeHome);
  if (takeHomePesewas < netPayPesewasFor(0)) {
    return {
      amount: null,
      takeHome: null,
      error: `Take home cannot be below ${(netPayPesewasFor(0) / 100).toFixed(2)}, ${belowMinimumReason}.`,
    };
  }
  const amountPesewas = amountPesewasForTakeHome(takeHomePesewas, netPayPesewasFor);
  if (amountPesewas === null) {
    return { amount: null, takeHome: null, error: unreachableRefusal };
  }
  return { amount: amountPesewas / 100, takeHome: netPayPesewasFor(amountPesewas) / 100, error: null };
}

/** The basic salary that pays the given take home with everything else in the row as it is. */
export function basicSalaryForTakeHome(
  takeHomeInput: string,
  rowInput: SalaryRowInput,
  payItems: PayItem[],
  rateSet: RateSet,
  reliefLabels: Record<string, string>,
): TakeHomeDerivation {
  const extrasResult = readExtras(rowInput, payItems);
  const extras = extrasResult.extras;
  const netPayPesewasFor =
    extras === null
      ? null
      : (basicPesewas: number) => payPesewasOf(basicPesewas, extras, rateSet, reliefLabels).pay?.netPay ?? Number.NEGATIVE_INFINITY;
  return deriveForTakeHome(
    takeHomeInput,
    netPayPesewasFor,
    "the rest of the row alone after deductions",
    "No basic salary the system can hold pays this take home.",
  );
}

/** The amount on one allowance that pays the given take home with everything else in the row as it is. */
export function allowanceForTakeHome(
  takeHomeInput: string,
  derivedPayItemId: number,
  rowInput: SalaryRowInput,
  payItems: PayItem[],
  rateSet: RateSet,
  reliefLabels: Record<string, string>,
): TakeHomeDerivation {
  const basicSalary = readAmountOrZero(rowInput.basic_salary);
  if (basicSalary === null) {
    return { amount: null, takeHome: null, error: "Enter a basic salary of 0 or more." };
  }
  const extrasResult = readExtras(rowInput, payItems);
  const extras = extrasResult.extras;
  const basicPesewas = toPesewas(basicSalary);
  const netPayPesewasFor =
    extras === null
      ? null
      : (allowancePesewas: number) =>
          payPesewasOf(basicPesewas, extras, rateSet, reliefLabels, { payItemId: derivedPayItemId, amountPesewas: allowancePesewas }).pay
            ?.netPay ?? Number.NEGATIVE_INFINITY;
  return deriveForTakeHome(
    takeHomeInput,
    netPayPesewasFor,
    "the rest of the row alone after SSNIT, PAYE and deductions",
    "No allowance the system can hold pays this take home.",
  );
}
