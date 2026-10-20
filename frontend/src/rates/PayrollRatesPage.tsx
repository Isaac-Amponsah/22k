// Statutory rates: effective-dated SSNIT percentages (with the Tier 1 / Tier 2 split and the Tier 3 relief
// cap), monthly PAYE bands and personal relief amounts. They are the law, so one
// set serves every client; a payroll run uses the set in force for its month. A set is never edited:
// a change in the law is a new set with its own start date.

import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { formatDate, formatMoney, formatPercent } from "../lib/formatting";
import { queryKeys } from "../lib/queryKeys";
import { ActionsMenu } from "../shared/ActionsMenu";
import { Button, ConfirmDialog, EmptyState, INPUT_CLASSES, LoadingState, Notice, PageHeading, Panel, TextField } from "../shared/ui";
import type { RateSet } from "../types";

interface ReliefTypeOfRates {
  relief_code: string;
  label: string;
  is_percent_of_assessable: boolean;
}

interface RateSetsAnswer {
  rate_sets: RateSet[];
  relief_types: ReliefTypeOfRates[];
}

interface ReliefInput {
  amount: string;
  max_claims: string;
}

interface BandInput {
  band_width: string;
  rate_percent: string;
}

export function PayrollRatesPage() {
  const queryClient = useQueryClient();
  const [deleteTarget, setDeleteTarget] = useState<RateSet | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "refusal"; text: string } | null>(null);

  const rateSetsQuery = useQuery({
    queryKey: queryKeys.rateSets(),
    queryFn: async () => (await api.get<RateSetsAnswer>("/api/payroll-rates")).data,
  });

  const deleteMutation = useMutation({
    mutationFn: (rateSet: RateSet) => api.delete(`/api/payroll-rates/${rateSet.statutory_rate_id}`),
    onSuccess: (result) => {
      setNotice({ tone: "success", text: result.message });
      return queryClient.invalidateQueries({ queryKey: queryKeys.rateSets() });
    },
    onError: (thrown) => setNotice({ tone: "refusal", text: errorMessage(thrown) }),
    onSettled: () => setDeleteTarget(null),
  });

  const rateSets = rateSetsQuery.data?.rate_sets ?? [];
  const reliefTypes = rateSetsQuery.data?.relief_types ?? [];
  const today = new Date().toISOString().slice(0, 10);
  // Newest first, so the set in force today is the first one that has already started.
  const currentRateSetId = rateSets.find((rateSet) => rateSet.effective_from <= today)?.statutory_rate_id ?? null;

  return (
    <main className="mx-auto max-w-7xl px-4 py-8">
      <PageHeading title="Payroll rates" />

      {notice ? (
        <div className="mb-4">
          <Notice tone={notice.tone}>{notice.text}</Notice>
        </div>
      ) : null}

      <div className="grid gap-6 lg:grid-cols-[24rem_1fr]">
        {rateSetsQuery.isSuccess ? (
          <RateSetForm
            key={rateSets[0]?.statutory_rate_id ?? "first"}
            latestRateSet={rateSets[0] ?? null}
            reliefTypes={reliefTypes}
            onAdded={(message) => setNotice({ tone: "success", text: message })}
          />
        ) : (
          <Panel>
            <LoadingState />
          </Panel>
        )}

        <div className="space-y-4">
          {rateSetsQuery.isError ? <Notice tone="refusal">{errorMessage(rateSetsQuery.error)}</Notice> : null}
          {rateSetsQuery.isSuccess && rateSets.length === 0 ? (
            <Panel>
              <EmptyState>No rate sets yet. Payroll cannot be worked out until one is added.</EmptyState>
            </Panel>
          ) : null}
          {rateSets.map((rateSet) => (
            <Panel key={rateSet.statutory_rate_id}>
              <div className="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-4 py-3">
                <div>
                  <h2 className="font-serif text-lg font-semibold">
                    In force from {formatDate(rateSet.effective_from)}
                    {rateSet.statutory_rate_id === currentRateSetId ? (
                      <span className="ml-2 rounded-full bg-brand px-2.5 py-0.5 align-middle font-sans text-xs font-semibold text-white">
                        Current
                      </span>
                    ) : null}
                  </h2>
                  <p className="text-sm text-ink-soft">
                    Employee SSNIT {formatPercent(rateSet.employee_ssnit_percent)}%, employer SSNIT{" "}
                    {formatPercent(rateSet.employer_ssnit_percent)}%. Tier 1 {formatPercent(rateSet.ssnit_tier1_percent)}%, Tier 2{" "}
                    {formatPercent(
                      Number(rateSet.employee_ssnit_percent) + Number(rateSet.employer_ssnit_percent) - Number(rateSet.ssnit_tier1_percent),
                    )}
                    %. Tier 3 relieved up to {formatPercent(rateSet.tier3_relief_cap_percent)}%.
                  </p>
                </div>
                <ActionsMenu
                  label={`Actions for rates in force from ${formatDate(rateSet.effective_from)}`}
                  actions={[{ label: "Delete", onSelect: () => setDeleteTarget(rateSet), isDangerous: true }]}
                />
              </div>
              <table className="w-full text-sm">
                <thead className="text-ink-soft">
                  <tr>
                    <th className="px-4 py-2 text-left font-medium">Band</th>
                    <th className="figure px-4 py-2 font-medium">Monthly width (GHS)</th>
                    <th className="figure px-4 py-2 font-medium">Rate</th>
                  </tr>
                </thead>
                <tbody>
                  {rateSet.bands.map((band) => (
                    <tr key={band.band_order} className="border-t border-rule/60">
                      <td className="px-4 py-2">{band.band_order}</td>
                      <td className="figure px-4 py-2">{band.band_width === null ? "Everything above" : formatMoney(band.band_width)}</td>
                      <td className="figure px-4 py-2">{formatPercent(band.rate_percent)}%</td>
                    </tr>
                  ))}
                </tbody>
              </table>
              {Object.keys(rateSet.reliefs).length > 0 ? (
                <table className="w-full border-t border-rule text-sm">
                  <thead className="text-ink-soft">
                    <tr>
                      <th className="px-4 py-2 text-left font-medium">Personal relief</th>
                      <th className="figure px-4 py-2 font-medium">Annual (GHS)</th>
                      <th className="figure px-4 py-2 font-medium">Most claims</th>
                    </tr>
                  </thead>
                  <tbody>
                    {reliefTypes
                      .filter((reliefType) => rateSet.reliefs[reliefType.relief_code] !== undefined)
                      .map((reliefType) => {
                        const reliefRate = rateSet.reliefs[reliefType.relief_code];
                        return (
                          <tr key={reliefType.relief_code} className="border-t border-rule/60">
                            <td className="px-4 py-2">{reliefType.label}</td>
                            <td className="figure px-4 py-2">
                              {reliefRate.percent_of_assessable !== null
                                ? `${formatPercent(reliefRate.percent_of_assessable)}% of assessable income`
                                : formatMoney(reliefRate.annual_amount)}
                            </td>
                            <td className="figure px-4 py-2">{reliefRate.max_claims}</td>
                          </tr>
                        );
                      })}
                  </tbody>
                </table>
              ) : null}
              {rateSet.note ? <p className="border-t border-rule px-4 py-3 text-sm text-ink-soft">{rateSet.note}</p> : null}
            </Panel>
          ))}
        </div>
      </div>

      <ConfirmDialog
        isOpen={deleteTarget !== null}
        title="Delete this rate set?"
        explanation="This cannot be undone."
        confirmLabel="Delete rate set"
        isDangerous
        isWorking={deleteMutation.isPending}
        onConfirm={() => deleteTarget && deleteMutation.mutate(deleteTarget)}
        onCancel={() => setDeleteTarget(null)}
      />
    </main>
  );
}

/** A new set starts from the latest one's figures, so only what changed needs retyping. */
interface RateSetFormProps {
  latestRateSet: RateSet | null;
  reliefTypes: ReliefTypeOfRates[];
  onAdded: (message: string) => void;
}

function RateSetForm({ latestRateSet, reliefTypes, onAdded }: RateSetFormProps) {
  const queryClient = useQueryClient();
  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [employeeSsnitPercent, setEmployeeSsnitPercent] = useState(latestRateSet ? formatPercent(latestRateSet.employee_ssnit_percent) : "");
  const [employerSsnitPercent, setEmployerSsnitPercent] = useState(latestRateSet ? formatPercent(latestRateSet.employer_ssnit_percent) : "");
  const [ssnitTier1Percent, setSsnitTier1Percent] = useState(latestRateSet ? formatPercent(latestRateSet.ssnit_tier1_percent) : "");
  const [tier3ReliefCapPercent, setTier3ReliefCapPercent] = useState(
    latestRateSet ? formatPercent(latestRateSet.tier3_relief_cap_percent) : "",
  );
  const [reliefInputs, setReliefInputs] = useState<Record<string, ReliefInput>>(() =>
    Object.fromEntries(
      reliefTypes.map((reliefType) => {
        const reliefRate = latestRateSet?.reliefs[reliefType.relief_code];
        const amount =
          reliefRate === undefined
            ? ""
            : reliefRate.percent_of_assessable !== null
              ? formatPercent(reliefRate.percent_of_assessable)
              : Number(reliefRate.annual_amount).toFixed(2);
        return [reliefType.relief_code, { amount, max_claims: String(reliefRate?.max_claims ?? 1) }];
      }),
    ),
  );
  const [note, setNote] = useState("");
  const [bandInputs, setBandInputs] = useState<BandInput[]>(() =>
    latestRateSet
      ? latestRateSet.bands.map((band) => ({
          band_width: band.band_width === null ? "" : Number(band.band_width).toFixed(2),
          rate_percent: formatPercent(band.rate_percent),
        }))
      : [{ band_width: "", rate_percent: "" }],
  );

  const addMutation = useMutation({
    mutationFn: () =>
      api.post("/api/payroll-rates", {
        effective_from: effectiveFrom,
        employee_ssnit_percent: employeeSsnitPercent,
        employer_ssnit_percent: employerSsnitPercent,
        ssnit_tier1_percent: ssnitTier1Percent,
        tier3_relief_cap_percent: tier3ReliefCapPercent,
        reliefs: reliefInputs,
        note,
        // The last band is the open one, whatever width was typed in it before it became last.
        band_width: bandInputs.map((band, bandIndex) => (bandIndex === bandInputs.length - 1 ? "" : band.band_width)),
        rate_percent: bandInputs.map((band) => band.rate_percent),
      }),
    onSuccess: async (result) => {
      await queryClient.invalidateQueries({ queryKey: queryKeys.rateSets() });
      onAdded(result.message);
    },
  });

  const fieldErrors = fieldErrorsOf(addMutation.error);

  function changeBand(bandIndex: number, changedBand: Partial<BandInput>) {
    setBandInputs((currentBands) => currentBands.map((band, index) => (index === bandIndex ? { ...band, ...changedBand } : band)));
  }

  function handleSubmit(submitEvent: FormEvent) {
    submitEvent.preventDefault();
    addMutation.mutate();
  }

  return (
    <Panel className="h-fit p-5">
      <h2 className="mb-4 font-serif text-lg font-semibold">Add a rate set</h2>
      <form onSubmit={handleSubmit} className="space-y-4">
        {addMutation.isError && Object.keys(fieldErrors).length === 0 ? (
          <Notice tone="refusal">{errorMessage(addMutation.error)}</Notice>
        ) : null}
        <TextField
          label="In force from"
          name="effective_from"
          type="date"
          required
          value={effectiveFrom}
          error={fieldErrors.effective_from}
          onChange={(changeEvent) => setEffectiveFrom(changeEvent.target.value)}
        />
        <div className="grid grid-cols-2 gap-3">
          <TextField
            label="Employee SSNIT (%)"
            name="employee_ssnit_percent"
            type="number"
            min="0"
            max="100"
            step="0.01"
            required
            value={employeeSsnitPercent}
            error={fieldErrors.employee_ssnit_percent}
            onChange={(changeEvent) => setEmployeeSsnitPercent(changeEvent.target.value)}
          />
          <TextField
            label="Employer SSNIT (%)"
            name="employer_ssnit_percent"
            type="number"
            min="0"
            max="100"
            step="0.01"
            required
            value={employerSsnitPercent}
            error={fieldErrors.employer_ssnit_percent}
            onChange={(changeEvent) => setEmployerSsnitPercent(changeEvent.target.value)}
          />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <TextField
            label="Tier 1 share (%)"
            name="ssnit_tier1_percent"
            type="number"
            min="0"
            max="100"
            step="0.01"
            required
            value={ssnitTier1Percent}
            error={fieldErrors.ssnit_tier1_percent}
            onChange={(changeEvent) => setSsnitTier1Percent(changeEvent.target.value)}
          />
          <TextField
            label="Tier 3 relief cap (%)"
            name="tier3_relief_cap_percent"
            type="number"
            min="0"
            max="100"
            step="0.01"
            required
            value={tier3ReliefCapPercent}
            error={fieldErrors.tier3_relief_cap_percent}
            onChange={(changeEvent) => setTier3ReliefCapPercent(changeEvent.target.value)}
          />
        </div>

        <fieldset>
          <legend className="mb-1 text-sm font-medium">Monthly PAYE bands</legend>
          <div className="space-y-2">
            {bandInputs.map((band, bandIndex) => {
              const isLastBand = bandIndex === bandInputs.length - 1;
              return (
                <div key={bandIndex} className="flex items-center gap-2">
                  <input
                    type="number"
                    min="0"
                    step="0.01"
                    aria-label={`Band ${bandIndex + 1} width in GHS`}
                    placeholder={isLastBand ? "Everything above" : "Width (GHS)"}
                    disabled={isLastBand}
                    className={INPUT_CLASSES}
                    value={isLastBand ? "" : band.band_width}
                    onChange={(changeEvent) => changeBand(bandIndex, { band_width: changeEvent.target.value })}
                  />
                  <input
                    type="number"
                    min="0"
                    max="100"
                    step="0.01"
                    required
                    aria-label={`Band ${bandIndex + 1} rate in percent`}
                    placeholder="Rate %"
                    className={`${INPUT_CLASSES} w-24`}
                    value={band.rate_percent}
                    onChange={(changeEvent) => changeBand(bandIndex, { rate_percent: changeEvent.target.value })}
                  />
                  <Button
                    aria-label={`Remove band ${bandIndex + 1}`}
                    disabled={bandInputs.length === 1}
                    onClick={() => setBandInputs(bandInputs.filter((_, index) => index !== bandIndex))}
                  >
                    ×
                  </Button>
                </div>
              );
            })}
          </div>
          {fieldErrors.bands ? <p className="mt-1 text-sm text-refusal">{fieldErrors.bands}</p> : null}
          <Button className="mt-2" onClick={() => setBandInputs([...bandInputs, { band_width: "", rate_percent: "" }])}>
            Add band
          </Button>
        </fieldset>

        <fieldset>
          <legend className="mb-1 text-sm font-medium">Personal reliefs (annual)</legend>
          <div className="space-y-2">
            {reliefTypes.map((reliefType) => {
              const reliefInput = reliefInputs[reliefType.relief_code];
              const changeRelief = (changedInput: Partial<ReliefInput>) =>
                setReliefInputs({ ...reliefInputs, [reliefType.relief_code]: { ...reliefInput, ...changedInput } });
              return (
                <div key={reliefType.relief_code} className="flex items-center gap-2">
                  <span className="flex-1 text-sm">{reliefType.label}</span>
                  <input
                    type="number"
                    min="0"
                    max={reliefType.is_percent_of_assessable ? "100" : undefined}
                    step="0.01"
                    aria-label={`${reliefType.label} ${reliefType.is_percent_of_assessable ? "percent of assessable income" : "annual amount in GHS"}`}
                    placeholder={reliefType.is_percent_of_assessable ? "%" : "GHS"}
                    className={`${INPUT_CLASSES} w-28`}
                    value={reliefInput.amount}
                    onChange={(changeEvent) => changeRelief({ amount: changeEvent.target.value })}
                  />
                  <input
                    type="number"
                    min="1"
                    max="99"
                    step="1"
                    aria-label={`${reliefType.label} most claims`}
                    title="Most claims"
                    disabled={reliefType.is_percent_of_assessable}
                    className={`${INPUT_CLASSES} w-16`}
                    value={reliefType.is_percent_of_assessable ? "1" : reliefInput.max_claims}
                    onChange={(changeEvent) => changeRelief({ max_claims: changeEvent.target.value })}
                  />
                </div>
              );
            })}
          </div>
          {fieldErrors.reliefs ? <p className="mt-1 text-sm text-refusal">{fieldErrors.reliefs}</p> : null}
        </fieldset>

        <TextField label="Note (optional)" name="note" maxLength={500} value={note} onChange={(changeEvent) => setNote(changeEvent.target.value)} />

        <Button type="submit" variant="primary" className="w-full" disabled={addMutation.isPending}>
          {addMutation.isPending ? "Adding…" : "Add rate set"}
        </Button>
      </form>
    </Panel>
  );
}
