import React, { useMemo, useState } from 'react';
import { ChevronDown, RefreshCw } from 'lucide-react';
import type { RiskEngineErrorKind, RiskObservation } from '../lib/riskEngineApi';
import { riskEngineErrorMessage } from '../lib/riskEngineApi';
import {
  buildTrustTimelineJourneys,
  filterTrustTimelineJourneys,
  sortTrustTimelineJourneys,
  trustTimelineIsEmpty,
  type TrustTimelineJourney,
  type TrustTimelineNode,
} from '../lib/trustTimelineLogic';
import TimelineContextCard from './TimelineContextCard';
import TimelineEventCard from './TimelineEventCard';
import TimelineMetricCard from './TimelineMetricCard';
import TimelineObservationCard from './TimelineObservationCard';
import TimelineSignalCard from './TimelineSignalCard';

function TimelineConnector() {
  return (
    <div className="flex justify-center py-1" aria-hidden>
      <span className="text-zinc-600 text-xs">↓</span>
    </div>
  );
}

function TimelineNodeView({ node }: { node: TrustTimelineNode }) {
  switch (node.kind) {
    case 'event':
      return (
        <TimelineEventCard
          label={node.label}
          detail={node.detail}
          timestamp={node.timestamp}
          whyText={node.whyText}
        />
      );
    case 'metric':
      return <TimelineMetricCard label={node.label} detail={node.detail} />;
    case 'signal':
      return (
        <TimelineSignalCard label={node.label} detail={node.detail} whyText={node.whyText} />
      );
    case 'context':
      return (
        <TimelineContextCard label={node.label} detail={node.detail} whyText={node.whyText} />
      );
    case 'observation':
      return (
        <TimelineObservationCard
          label={node.label}
          detail={node.detail}
          whyText={node.whyText}
        />
      );
    default:
      return null;
  }
}

function TrustJourneySection({
  journey,
  defaultExpanded,
}: {
  journey: TrustTimelineJourney;
  defaultExpanded: boolean;
}) {
  const [expanded, setExpanded] = useState(defaultExpanded);
  const chainText =
    'risk_observation → context → signal → metric → event';

  return (
    <article className="rounded-xl border border-zinc-800 bg-[#09090e] overflow-hidden">
      <button
        type="button"
        onClick={() => setExpanded((value) => !value)}
        aria-expanded={expanded}
        className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left hover:bg-zinc-900/60 cursor-pointer"
      >
        <div className="min-w-0">
          <p className="font-mono text-[11px] text-amber-200/90">{journey.observationId}</p>
          {journey.observationTitle ? (
            <p className="text-xs text-zinc-400 truncate mt-0.5">{journey.observationTitle}</p>
          ) : null}
        </div>
        <ChevronDown
          className={`h-4 w-4 shrink-0 text-zinc-500 transition-transform ${expanded ? 'rotate-180' : ''}`}
        />
      </button>

      {expanded && (
        <div className="px-4 pb-4 space-y-0">
          <p className="mb-3 font-mono text-[10px] text-zinc-600 leading-relaxed select-text">
            {chainText}
          </p>
          {journey.nodes.map((node, index) => (
            <div key={node.id}>
              <TimelineNodeView node={node} />
              {index < journey.nodes.length - 1 ? <TimelineConnector /> : null}
            </div>
          ))}
        </div>
      )}
    </article>
  );
}

export default function TrustTimeline({
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
  const [filter, setFilter] = useState('');
  const [expandAll, setExpandAll] = useState(true);
  const [expandEpoch, setExpandEpoch] = useState(0);

  const journeys = useMemo(() => {
    const built = buildTrustTimelineJourneys(observations);
    const sorted = sortTrustTimelineJourneys(built);
    return filterTrustTimelineJourneys(sorted, filter);
  }, [observations, filter]);

  const isEmpty = !loading && !errorKind && trustTimelineIsEmpty(journeys);
  const showList = !loading && !errorKind && journeys.length > 0;

  return (
    <section
      className="rounded-2xl border border-indigo-500/20 bg-indigo-500/[0.04] p-5 space-y-3"
      aria-label="Trust Journey"
    >
      <div>
        <p className="font-mono text-[10px] uppercase tracking-wider text-indigo-300/90 font-bold">
          Trust Journey
        </p>
        <p className="text-[11px] text-zinc-500 mt-1 leading-relaxed">
          Event → Metric → Signal → Context → Risk Observation. Yorum, öneri veya risk puanı
          üretmez.
        </p>
      </div>

      {showList && (
        <div className="flex flex-wrap items-center gap-2">
          <input
            type="search"
            value={filter}
            onChange={(event) => setFilter(event.target.value)}
            placeholder="Observation filtrele…"
            className="min-w-[12rem] flex-1 rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-1.5 text-xs text-zinc-200 placeholder-zinc-600 focus:outline-none focus:border-indigo-500/40"
          />
          <button
            type="button"
            onClick={() => {
              setExpandAll((value) => !value);
              setExpandEpoch((epoch) => epoch + 1);
            }}
            className="rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-zinc-300 hover:bg-zinc-800 cursor-pointer"
          >
            {expandAll ? 'Tümünü daralt' : 'Tümünü genişlet'}
          </button>
        </div>
      )}

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

      {isEmpty && (
        <p className="text-xs text-zinc-600 leading-relaxed">
          Bu oda için Trust Journey oluşturulacak observation bulunamadı.
        </p>
      )}

      {showList && (
        <div className="max-h-[28rem] overflow-y-auto space-y-3 pr-1">
          {journeys.map((journey) => (
            <TrustJourneySection
              key={`${journey.observationId}-${expandEpoch}`}
              journey={journey}
              defaultExpanded={expandAll}
            />
          ))}
        </div>
      )}
    </section>
  );
}
