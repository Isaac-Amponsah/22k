// The pay a salary works out to, mirroring App\Services\PayrollCalculator on the server: every figure
// in whole pesewas, rounded once. A preview only — the server works the figures out again on save.

import type { AllowanceMode, RateSet } from "../types";

export interface SalaryPreviewFigures {
  allowance: number;
  chargeable_income: number;
  paye: number;
  net_pay: number;
}

export type SalaryPreviewResult = { figures: SalaryPreviewFigures; error: null } | { figures: null; error: string };

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

export function previewSalary(
  basicSalaryInput: string,
  allowanceMode: AllowanceMode,
  flatAllowanceInput: string,
  targetIncomeInput: string,
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
  const employeeSsnitPesewas = Math.round((basicPesewas * Number(rateSet.employee_ssnit_percent)) / 100);
  const basicLessSsnitPesewas = basicPesewas - employeeSsnitPesewas;

  let allowancePesewas: number;
  if (allowanceMode === "target") {
    const targetIncome = readAmount(targetIncomeInput);
    if (targetIncome === null) {
      return { figures: null, error: "Enter the target chargeable income." };
    }
    allowancePesewas = toPesewas(targetIncome) - basicLessSsnitPesewas;
    if (allowancePesewas < 0) {
      return {
        figures: null,
        error: `Target chargeable income cannot be below basic salary less SSNIT (${(basicLessSsnitPesewas / 100).toFixed(2)}).`,
      };
    }
  } else {
    const flatAllowance = flatAllowanceInput.trim() === "" ? 0 : readAmount(flatAllowanceInput);
    if (flatAllowance === null) {
      return { figures: null, error: "Enter an allowance of 0 or more." };
    }
    allowancePesewas = toPesewas(flatAllowance);
  }

  const chargeablePesewas = basicLessSsnitPesewas + allowancePesewas;
  const taxPesewas = payePesewas(chargeablePesewas, rateSet);

  return {
    figures: {
      allowance: allowancePesewas / 100,
      chargeable_income: chargeablePesewas / 100,
      paye: taxPesewas / 100,
      net_pay: (chargeablePesewas - taxPesewas) / 100,
    },
    error: null,
  };
}
