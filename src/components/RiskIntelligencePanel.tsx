import React from 'react';
import { RefreshCw } from 'lucide-react';
import type { RiskEngineErrorKind, RiskObservation } from '../lib/riskEngineApi';
import { riskEngineErrorMessage } from '../lib/riskEngineApi';
import RiskObservationCard from './RiskObservationCard';

export default function RiskIntelligencePanel({
  observations,
  loading,
  errorKind,
  onRetry,
}: {
  observations: RiskObservation[];
  loading: boolean;
  errorKind?: RiskEngineErrorKind | null;
  onRetry?: () => void;
}) {
  const showEmpty = !loading && !errorKind && observations.length === 0;
  const showList = !loading && !errorKind && observations.length > 0;

  return (
    <section
      className="rounded-2xl border border-amber-500/20 bg-amber-500/[0.04] p-5 space-y-3"
      aria-label="Risk Intelligence"
    >
      <div>
        <p className="font-mono text-[10px] uppercase tracking-wider text-amber-300/90 font-bold">
          Risk Intelligence
        </p>
        <p className="text-[11px] text-zinc-500 mt-1 leading-relaxed">
          Salt okunur gözlem paketi. Karar, ceza, settlement önerisi veya otomatik işlem üretmez.
        </p>
      </div>

      {loading && (
        <p className="text-xs text-zinc-500" role="status">
          Yükleniyor…
        </p>
      )}

      {errorKind && !loading && (
        <div className="rounded-xl border border-rose-500/20 bg-rose-500/5 px-4 py-3 space-y-3">
          <p className="text-xs text-rose-200/90 leading-relaxed">{riskEngineErrorMessage(errorKind)}</p>
          {onRetry ? (
            <button
              type="button"
              onClick={onRetry}
              className="inline-flex items-center gap-2 rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-zinc-200 hover:bg-zinc-800 cursor-pointer"
            >
              <RefreshCw className="h-3.5 w-3.5" />
              Tekrar dene
            </button>
          ) : null}
        </div>
      )}

      {showEmpty && (
        <p className="text-xs text-zinc-600 leading-relaxed">
          Bu oda için henüz Risk Observation kaydı yok.
        </p>
      )}

      {showList && (
        <ul className="space-y-3">
          {observations.map((obs) => (
            <RiskObservationCard key={obs.observation_id} observation={obs} />
          ))}
        </ul>
      )}
    </section>
  );
}
