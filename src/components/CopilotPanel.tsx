import React, { useState } from 'react';
import { RefreshCw } from 'lucide-react';
import {
  COPILOT_HUMAN_DISCLAIMER,
  copilotAssistantMessage,
  copilotIntentLabel,
  copilotUserMessage,
  fetchCopilotExplanation,
  type CopilotConversationMessage,
  type CopilotIntent,
} from '../lib/copilotApi';
import type { RiskObservation } from '../lib/riskEngineApi';
import { riskEngineErrorMessage, type RiskEngineErrorKind } from '../lib/riskEngineApi';
import CopilotConversation from './CopilotConversation';

const QUICK_INTENTS: CopilotIntent[] = [
  'overview',
  'why_observation',
  'evidence',
  'context_chain',
  'signal_chain',
  'event_chain',
];

export default function CopilotPanel({
  roomId,
  observations,
  loading,
  errorKind,
  onRetry,
}: {
  roomId: string;
  observations: RiskObservation[];
  loading: boolean;
  errorKind?: RiskEngineErrorKind | null;
  onRetry?: () => void;
}) {
  const [messages, setMessages] = useState<CopilotConversationMessage[]>([]);
  const [selectedObservationId, setSelectedObservationId] = useState('');
  const [copilotLoading, setCopilotLoading] = useState(false);
  const [copilotError, setCopilotError] = useState<string | null>(null);

  const ask = async (intent: CopilotIntent) => {
    if (!roomId || copilotLoading) return;
    const observationId =
      intent === 'overview'
        ? null
        : (selectedObservationId || observations[0]?.observation_id || null);

    setCopilotLoading(true);
    setCopilotError(null);
    setMessages((prev) => [...prev, copilotUserMessage(intent, observationId)]);
    try {
      const response = await fetchCopilotExplanation(roomId, intent, observationId ?? undefined);
      setMessages((prev) => [...prev, copilotAssistantMessage(response)]);
    } catch (err) {
      setCopilotError(err instanceof Error ? err.message : 'Copilot yanıtı alınamadı.');
    } finally {
      setCopilotLoading(false);
    }
  };

  return (
    <section
      className="rounded-2xl border border-cyan-500/20 bg-cyan-500/[0.04] p-5 space-y-3"
      aria-label="Intelligence Copilot"
    >
      <div>
        <p className="font-mono text-[10px] uppercase tracking-wider text-cyan-300/90 font-bold">
          Intelligence Copilot
        </p>
        <p className="text-[11px] text-zinc-500 mt-1 leading-relaxed">
          Risk Engine paketini doğal dilde açıklar. Karar vermez; yalnızca mevcut explainability zincirini
          okur.
        </p>
        <p className="text-[10px] text-zinc-600 mt-2 leading-relaxed">{COPILOT_HUMAN_DISCLAIMER}</p>
      </div>

      {observations.length > 1 && (
        <label className="block text-xs text-zinc-500">
          Observation
          <select
            value={selectedObservationId || observations[0]?.observation_id || ''}
            onChange={(event) => setSelectedObservationId(event.target.value)}
            className="mt-1 w-full rounded-lg border border-zinc-800 bg-zinc-900 px-3 py-2 text-xs text-zinc-200"
          >
            {observations.map((obs) => (
              <option key={obs.observation_id} value={obs.observation_id}>
                {obs.observation_id}
                {obs.title ? ` — ${obs.title}` : ''}
              </option>
            ))}
          </select>
        </label>
      )}

      <div className="flex flex-wrap gap-2">
        {QUICK_INTENTS.map((intent) => (
          <button
            key={intent}
            type="button"
            disabled={loading || copilotLoading || !!errorKind}
            onClick={() => void ask(intent)}
            className="rounded-lg border border-zinc-700 bg-zinc-900 px-3 py-1.5 text-[11px] font-semibold text-zinc-300 hover:bg-zinc-800 disabled:opacity-50 cursor-pointer"
          >
            {copilotIntentLabel(intent)}
          </button>
        ))}
      </div>

      {(loading || copilotLoading) && (
        <p className="text-xs text-zinc-500" role="status">
          Yükleniyor…
        </p>
      )}

      {errorKind && !loading && (
        <div className="rounded-xl border border-rose-500/20 bg-rose-500/5 px-4 py-3 space-y-3">
          <p className="text-xs text-rose-200/90">{riskEngineErrorMessage(errorKind)}</p>
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

      {copilotError && (
        <p className="text-xs text-rose-300/90">{copilotError}</p>
      )}

      <CopilotConversation messages={messages} />
    </section>
  );
}