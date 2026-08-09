import React, { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import type { RiskObservation, RiskObservationSignalFrame } from '../lib/riskEngineApi';

function ChainStep({
  label,
  children,
}: {
  label: string;
  children: React.ReactNode;
}) {
  return (
    <div className="relative pl-4 border-l border-zinc-800">
      <p className="text-[10px] uppercase tracking-wider text-zinc-500 font-bold mb-1.5">{label}</p>
      <div className="space-y-1.5">{children}</div>
    </div>
  );
}

export default function ExplainabilityTree({ observation }: { observation: RiskObservation }) {
  const [open, setOpen] = useState(false);
  const chain =
    observation.explainability?.chain ??
    observation.evidence_chain?.chain ??
    'risk_observation → context → signal → metric → event';

  const contexts = observation.contexts_used ?? observation.explainability?.contexts ?? [];
  const signals = observation.signals_used ?? observation.explainability?.signals ?? [];
  const metrics =
    observation.metrics_used && Object.keys(observation.metrics_used).length > 0
      ? observation.metrics_used
      : observation.explainability?.metrics;

  const eventRows = (signals as RiskObservationSignalFrame[]).flatMap((sig) => {
    if (!Array.isArray(sig.event_sources)) return [];
    return sig.event_sources.map((evt, idx) => {
      const row = evt as Record<string, unknown>;
      const label =
        (row.event_type as string) ||
        (row.type as string) ||
        (row.id as string) ||
        `kayıt-${idx + 1}`;
      return { key: `${sig.signal_id ?? 'sig'}-${idx}`, label };
    });
  });

  return (
    <div className="mt-3 border-t border-zinc-800/80 pt-3">
      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="flex w-full items-center justify-between gap-2 text-left text-xs font-semibold text-zinc-400 hover:text-zinc-200 cursor-pointer"
      >
        <span>NEDEN?</span>
        <ChevronDown
          className={`h-4 w-4 shrink-0 transition-transform ${open ? 'rotate-180' : ''}`}
        />
      </button>

      {open && (
        <div className="mt-3 space-y-3 text-xs text-zinc-400">
          <p className="font-mono text-[10px] text-zinc-500 leading-relaxed">{chain}</p>

          <ChainStep label="Observation">
            <p className="font-mono text-zinc-200">{observation.observation_id}</p>
            {observation.title ? <p className="text-zinc-300">{observation.title}</p> : null}
          </ChainStep>

          <ChainStep label="Context">
            {contexts.length === 0 ? (
              <p className="text-zinc-600">—</p>
            ) : (
              <ul className="space-y-1">
                {contexts.map((ctx) => (
                  <li key={ctx.context_id ?? ctx.title} className="text-zinc-300">
                    <span className="font-mono text-zinc-200">{ctx.context_id}</span>
                    {ctx.title ? ` — ${ctx.title}` : ''}
                  </li>
                ))}
              </ul>
            )}
          </ChainStep>

          <ChainStep label="Signal">
            {signals.length === 0 ? (
              <p className="text-zinc-600">—</p>
            ) : (
              <ul className="space-y-2">
                {(signals as RiskObservationSignalFrame[]).map((sig) => (
                  <li key={sig.signal_id ?? sig.title}>
                    <span className="font-mono text-zinc-200">{sig.signal_id}</span>
                    {sig.title ? <span className="text-zinc-400"> — {sig.title}</span> : null}
                    {sig.description ? (
                      <p className="text-zinc-500 mt-0.5 leading-relaxed">{sig.description}</p>
                    ) : null}
                  </li>
                ))}
              </ul>
            )}
          </ChainStep>

          <ChainStep label="Metric">
            {metrics && Object.keys(metrics).length > 0 ? (
              <ul className="space-y-0.5 font-mono text-[11px]">
                {Object.entries(metrics).map(([key, value]) => (
                  <li key={key} className="text-zinc-400">
                    {key}: {String(value)}
                  </li>
                ))}
              </ul>
            ) : (
              <p className="text-zinc-600">—</p>
            )}
          </ChainStep>

          <ChainStep label="Event">
            {eventRows.length === 0 ? (
              <p className="text-zinc-600">—</p>
            ) : (
              <ul className="space-y-1">
                {eventRows.map((evt) => (
                  <li key={evt.key} className="font-mono text-[11px] text-zinc-400">
                    {evt.label}
                  </li>
                ))}
              </ul>
            )}
          </ChainStep>
        </div>
      )}
    </div>
  );
}
