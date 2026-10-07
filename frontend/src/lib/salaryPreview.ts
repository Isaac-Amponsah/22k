// The pay a salary works out to, mirroring App\Services\PayrollCalculator on the server: every figure
// in whole pesewas, rounded once. A preview only — the server works the figures out again on save.

import type { RateSet } from "../types";

export interface SalaryPreviewFigures {
  employee_ssnit: number;
  allowance: number;
  gross_pay: number;
  chargeable_income: number;
  paye: number;
  net_pay: number;
}

export type SalaryPreviewResult = { figures: SalaryPreviewFigures; error: null } | { figures: null; error: string };

/** The largest amount a salary column holds on the server (numeric(12,2)). */
const MAX_AMOUNT_PESEWAS = 999_999_999_999;

function toPesewas(amount: number): number {
  return Math.round(amount * 100);
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

function employeeSsnitPesewasOf(basicPesewas: number, rateSet: RateSet): number {
  return Math.round((basicPesewas * Number(rateSet.employee_ssnit_percent)) / 100);
}

function netPayPesewasOf(basicPesewas: number, allowancePesewas: number, rateSet: RateSet): number {
  const chargeablePesewas = basicPesewas - employeeSsnitPesewasOf(basicPesewas, rateSet) + allowancePesewas;
  return chargeablePesewas - payePesewas(chargeablePesewas, rateSet);
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

/** A blank amount counts as 0; null when it is not an amount of 0 or more. */
function readAmountOrZero(typedAmount: string): number | null {
  return typedAmount.trim() === "" ? 0 : readAmount(typedAmount);
}

/** The basic salary that pays the given take home on top of the allowance (blank allowance = none). */
export function basicSalaryForTakeHome(takeHomeInput: string, flatAllowanceInput: string, rateSet: RateSet): TakeHomeDerivation {
  const takeHome = readAmount(takeHomeInput);
  if (takeHome === null) {
    return { amount: null, takeHome: null, error: "Enter a take home of 0 or more." };
  }
  const flatAllowance = readAmountOrZero(flatAllowanceInput);
  if (flatAllowance === null) {
    return { amount: null, takeHome: null, error: "Enter an allowance of 0 or more." };
  }

  const takeHomePesewas = toPesewas(takeHome);
  const allowancePesewas = toPesewas(flatAllowance);
  const netPayPesewasFor = (basicPesewas: number) => netPayPesewasOf(basicPesewas, allowancePesewas, rateSet);
  if (takeHomePesewas < netPayPesewasFor(0)) {
    return {
      amount: null,
      takeHome: null,
      error: `Take home cannot be below ${(netPayPesewasFor(0) / 100).toFixed(2)}, the allowance alone after PAYE.`,
    };
  }

  const basicPesewas = amountPesewasForTakeHome(takeHomePesewas, netPayPesewasFor);
  if (basicPesewas === null) {
    return { amount: null, takeHome: null, error: "No basic salary the system can hold pays this take home." };
  }
  return { amount: basicPesewas / 100, takeHome: netPayPesewasFor(basicPesewas) / 100, error: null };
}

/** The allowance that pays the given take home on top of the basic salary. */
export function allowanceForTakeHome(takeHomeInput: string, basicSalaryInput: string, rateSet: RateSet): TakeHomeDerivation {
  const takeHome = readAmount(takeHomeInput);
  if (takeHome === null) {
    return { amount: null, takeHome: null, error: "Enter a take home of 0 or more." };
  }
  const basicSalary = readAmountOrZero(basicSalaryInput);
  if (basicSalary === null) {
    return { amount: null, takeHome: null, error: "Enter a basic salary of 0 or more." };
  }

  const takeHomePesewas = toPesewas(takeHome);
  const basicPesewas = toPesewas(basicSalary);
  const netPayPesewasFor = (allowancePesewas: number) => netPayPesewasOf(basicPesewas, allowancePesewas, rateSet);
  if (takeHomePesewas < netPayPesewasFor(0)) {
    return {
      amount: null,
      takeHome: null,
      error: `Take home cannot be below ${(netPayPesewasFor(0) / 100).toFixed(2)}, the basic salary alone after SSNIT and PAYE.`,
    };
  }

  const allowancePesewas = amountPesewasForTakeHome(takeHomePesewas, netPayPesewasFor);
  if (allowancePesewas === null) {
    return { amount: null, takeHome: null, error: "No allowance the system can hold pays this take home." };
  }
  return { amount: allowancePesewas / 100, takeHome: netPayPesewasFor(allowancePesewas) / 100, error: null };
}

export function previewSalary(
  basicSalaryInput: string,
  flatAllowanceInput: string,
  rateSet: RateSet,
): SalaryPreviewResult | null {
  if (basicSalaryInput.trim() === "") {
    return null;
  }
  const basicSalary = readAmount(basicSalaryInput);
  if (basicSalary === null) {
    return { figures: null, error: "Enter a basic salary of 0 or more." };
  }

  const basicPesewas = toPesewas(basicSalary);
  const employeeSsnitPesewas = employeeSsnitPesewasOf(basicPesewas, rateSet);
  const basicLessSsnitPesewas = basicPesewas - employeeSsnitPesewas;

  const flatAllowance = readAmountOrZero(flatAllowanceInput);
  if (flatAllowance === null) {
    return { figures: null, error: "Enter an allowance of 0 or more." };
  }
  const allowancePesewas = toPesewas(flatAllowance);

  const chargeablePesewas = basicLessSsnitPesewas + allowancePesewas;
  const taxPesewas = payePesewas(chargeablePesewas, rateSet);

  return {
    figures: {
      employee_ssnit: employeeSsnitPesewas / 100,
      allowance: allowancePesewas / 100,
      gross_pay: (basicPesewas + allowancePesewas) / 100,
      chargeable_income: chargeablePesewas / 100,
      paye: taxPesewas / 100,
      net_pay: (chargeablePesewas - taxPesewas) / 100,
    },
    error: null,
  };
}
