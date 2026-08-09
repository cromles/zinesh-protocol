import React from 'react';
import {
  riskConfidenceLabel,
  riskConfidenceTone,
  type RiskObservation,
} from '../lib/riskEngineApi';
import ExplainabilityTree from './ExplainabilityTree';

export default function RiskObservationCard({ observation }: { observation: RiskObservation }) {
  const tone = riskConfidenceTone(observation.confidence);

  return (
    <li className="rounded-xl border border-zinc-800 bg-[#09090e] p-4 space-y-2">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <p className="font-mono text-[11px] text-amber-200/90">{observation.observation_id}</p>
        <span
          className={`inline-flex items-center gap-1.5 rounded-full border px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide ${tone.badge}`}
          title="Kanıt tamlığı (Evidence Completeness)"
        >
          <span className={`h-1.5 w-1.5 rounded-full ${tone.dot}`} aria-hidden />
          {riskConfidenceLabel(observation.confidence)}
        </span>
      </div>
      <p className="text-sm font-semibold text-white">{observation.title}</p>
      <p className="text-sm text-zinc-400 leading-relaxed">{observation.description}</p>
      <p className="text-[10px] text-zinc-600">Kanıt tamlığı — risk skoru değildir.</p>
      <ExplainabilityTree observation={observation} />
    </li>
  );
}
