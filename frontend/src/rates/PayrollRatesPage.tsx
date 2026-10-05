// Statutory rates: effective-dated SSNIT percentages and monthly PAYE bands. They are the law, so one
// set serves every client; a payroll run uses the set in force for its month. A set is never edited:
// a change in the law is a new set with its own start date.

import { useState } from "react";
import type { FormEvent } from "react";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import { api, errorMessage, fieldErrorsOf } from "../lib/apiClient";
import { formatDate, formatMoney, formatPercent } from "../lib/formatting";
import { queryKeys } from "../lib/queryKeys";
import { Button, ConfirmDialog, EmptyState, INPUT_CLASSES, LoadingState, Notice, PageHeading, Panel, TextField } from "../shared/ui";
import type { RateSet } from "../types";

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
    queryFn: async () => (await api.get<{ rate_sets: RateSet[] }>("/api/payroll-rates")).data.rate_sets,
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

  const rateSets = rateSetsQuery.data ?? [];
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
                    {formatPercent(rateSet.employer_ssnit_percent)}%
                  </p>
                </div>
                <Button variant="danger" onClick={() => setDeleteTarget(rateSet)}>
                  Delete
                </Button>
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
function RateSetForm({ latestRateSet, onAdded }: { latestRateSet: RateSet | null; onAdded: (message: string) => void }) {
  const queryClient = useQueryClient();
  const [effectiveFrom, setEffectiveFrom] = useState("");
  const [employeeSsnitPercent, setEmployeeSsnitPercent] = useState(latestRateSet ? formatPercent(latestRateSet.employee_ssnit_percent) : "");
  const [employerSsnitPercent, setEmployerSsnitPercent] = useState(latestRateSet ? formatPercent(latestRateSet.employer_ssnit_percent) : "");
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

        <TextField label="Note (optional)" name="note" maxLength={500} value={note} onChange={(changeEvent) => setNote(changeEvent.target.value)} />

        <Button type="submit" variant="primary" className="w-full" disabled={addMutation.isPending}>
          {addMutation.isPending ? "Adding…" : "Add rate set"}
        </Button>
      </form>
    </Panel>
  );
}
