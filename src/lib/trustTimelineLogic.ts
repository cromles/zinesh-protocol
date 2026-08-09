import type { RiskObservation, RiskObservationSignalFrame } from './riskEngineLogic';

export type TrustTimelineNodeKind =
  | 'event'
  | 'metric'
  | 'signal'
  | 'context'
  | 'observation';

export interface TrustTimelineNode {
  id: string;
  kind: TrustTimelineNodeKind;
  label: string;
  detail?: string;
  timestamp?: string;
  /** Runtime explainability metni — frontend yorumu değil */
  whyText: string;
  /** Sıralama: event zaman damgası veya katman sırası */
  sortKey: number;
  layerOrder: number;
}

export interface TrustTimelineJourney {
  observationId: string;
  observationTitle: string;
  nodes: TrustTimelineNode[];
  /** Eskiden yeniye sıralama anahtarı */
  journeySortKey: number;
}

const LAYER_ORDER: Record<TrustTimelineNodeKind, number> = {
  event: 0,
  metric: 1,
  signal: 2,
  context: 3,
  observation: 4,
};

/** Değiştirilemez journey katman sırası (Event → … → Observation). */
export const TRUST_TIMELINE_LAYER_ORDER: readonly TrustTimelineNodeKind[] = [
  'event',
  'metric',
  'signal',
  'context',
  'observation',
] as const;

/** Runtime event_type → görüntü etiketi (yeni event üretmez). */
export const TRUST_TIMELINE_EVENT_LABELS: Record<string, string> = {
  contract_created: 'Sözleşme oluşturuldu',
  terms_proposed: 'Müzakere başladı',
  terms_updated: 'Sözleşme güncellendi',
  terms_rejected: 'Teklif reddedildi',
  changes_requested: 'Revizyon talep edildi',
  counter_offer_created: 'Karşı teklif',
  terms_accepted: 'Sözleşme kabul edildi',
  contract_finalized: 'Sözleşme kesinleşti',
  escrow_funded: 'Emanet fonlandı',
  escrow_locked: 'Emanet kilitlendi',
  escrow_release_requested: 'Serbest bırakma talebi',
  settlement_started: 'Ödeme başladı',
  settlement_completed: 'Ödeme tamamlandı',
  settlement_failed: 'Ödeme başarısız',
  escrow_recovered: 'Emanet kurtarıldı',
  dispute_opened: 'Uyuşmazlık açıldı',
  dispute_deposit_paid: 'Uyuşmazlık depozitosu ödendi',
  dispute_resolved: 'Uyuşmazlık çözüldü',
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function parseTimestampMs(value: string): number | null {
  if (!value.trim()) return null;
  const ms = Date.parse(value);
  return Number.isNaN(ms) ? null : ms;
}

export function trustTimelineEventLabel(eventType: string): string {
  const key = eventType.trim();
  if (!key) return 'Olay';
  return TRUST_TIMELINE_EVENT_LABELS[key] ?? key;
}

function observationSignals(observation: RiskObservation): RiskObservationSignalFrame[] {
  return observation.signals_used ?? observation.explainability?.signals ?? [];
}

function observationContexts(observation: RiskObservation) {
  return observation.contexts_used ?? observation.explainability?.contexts ?? [];
}

function observationMetrics(observation: RiskObservation): Record<string, unknown> {
  if (observation.metrics_used && Object.keys(observation.metrics_used).length > 0) {
    return observation.metrics_used;
  }
  const fromExplain = observation.explainability?.metrics;
  return isRecord(fromExplain) ? fromExplain : {};
}

/** Tek observation için explainability zincirinden timeline düğümleri (yeni ilişki üretmez). */
export function buildTrustTimelineJourney(observation: RiskObservation): TrustTimelineJourney {
  const nodes: TrustTimelineNode[] = [];
  const signals = observationSignals(observation);
  const contexts = observationContexts(observation);
  const metrics = observationMetrics(observation);

  let eventSeq = 0;
  for (const signal of signals) {
    const sources = Array.isArray(signal.event_sources) ? signal.event_sources : [];
    for (const raw of sources) {
      if (!isRecord(raw)) continue;
      const eventType = String(raw.event_type ?? raw.type ?? '').trim();
      const createdAt = String(raw.created_at ?? '').trim();
      const ts = parseTimestampMs(createdAt);
      nodes.push({
        id: `event:${observation.observation_id}:${eventType}:${createdAt}:${eventSeq}`,
        kind: 'event',
        label: trustTimelineEventLabel(eventType),
        detail: eventType || undefined,
        timestamp: createdAt || undefined,
        whyText: JSON.stringify(raw, null, 0),
        sortKey: ts ?? eventSeq,
        layerOrder: LAYER_ORDER.event,
      });
      eventSeq += 1;
    }
  }

  const eventNodes = nodes.filter((node) => node.kind === 'event');
  eventNodes.sort((a, b) => a.sortKey - b.sortKey);

  const nonEventNodes: TrustTimelineNode[] = [];
  let metricSeq = 0;
  for (const [key, value] of Object.entries(metrics)) {
    nonEventNodes.push({
      id: `metric:${observation.observation_id}:${key}`,
      kind: 'metric',
      label: key,
      detail: String(value),
      whyText: `${key}: ${String(value)}`,
      sortKey: 10_000 + metricSeq,
      layerOrder: LAYER_ORDER.metric,
    });
    metricSeq += 1;
  }

  signals.forEach((signal, index) => {
    const signalId = String(signal.signal_id ?? '').trim() || `signal-${index}`;
    nonEventNodes.push({
      id: `signal:${observation.observation_id}:${signalId}`,
      kind: 'signal',
      label: signalId,
      detail: signal.title || signal.description || undefined,
      whyText: JSON.stringify(signal, null, 0),
      sortKey: 20_000 + index,
      layerOrder: LAYER_ORDER.signal,
    });
  });

  contexts.forEach((ctx, index) => {
    const contextId = String(ctx.context_id ?? '').trim() || `context-${index}`;
    nonEventNodes.push({
      id: `context:${observation.observation_id}:${contextId}`,
      kind: 'context',
      label: contextId,
      detail: ctx.title || undefined,
      whyText: JSON.stringify(ctx, null, 0),
      sortKey: 30_000 + index,
      layerOrder: LAYER_ORDER.context,
    });
  });

  nonEventNodes.push({
    id: `observation:${observation.observation_id}`,
    kind: 'observation',
    label: observation.observation_id,
    detail: observation.title || undefined,
    whyText: JSON.stringify(
      {
        observation_id: observation.observation_id,
        title: observation.title,
        description: observation.description,
        confidence: observation.confidence,
        explainability: observation.explainability,
        evidence_chain: observation.evidence_chain,
      },
      null,
      0,
    ),
    sortKey: 40_000,
    layerOrder: LAYER_ORDER.observation,
  });

  const orderedNodes = [...eventNodes, ...nonEventNodes];
  const firstEventTs = eventNodes.length > 0 ? eventNodes[0].sortKey : 99_999;

  return {
    observationId: observation.observation_id,
    observationTitle: observation.title,
    nodes: orderedNodes,
    journeySortKey: firstEventTs,
  };
}

/** Tüm observation'lar için Trust Journey listesi. */
export function buildTrustTimelineJourneys(observations: RiskObservation[]): TrustTimelineJourney[] {
  return observations.map((observation) => buildTrustTimelineJourney(observation));
}

/** Eskiden yeniye journey sıralaması. */
export function sortTrustTimelineJourneys(
  journeys: TrustTimelineJourney[],
): TrustTimelineJourney[] {
  return [...journeys].sort((a, b) => a.journeySortKey - b.journeySortKey);
}

/** Observation ID ile basit filtre (yeni algoritma yok). */
export function filterTrustTimelineJourneys(
  journeys: TrustTimelineJourney[],
  observationFilter: string,
): TrustTimelineJourney[] {
  const query = observationFilter.trim().toLowerCase();
  if (!query) return journeys;
  return journeys.filter(
    (journey) =>
      journey.observationId.toLowerCase().includes(query) ||
      journey.observationTitle.toLowerCase().includes(query),
  );
}

/** Explainability zinciri node türleriyle korunuyor mu? */
export function trustTimelineExplainabilityKinds(nodes: TrustTimelineNode[]): TrustTimelineNodeKind[] {
  return nodes.map((node) => node.kind);
}

export function trustTimelineIsEmpty(journeys: TrustTimelineJourney[]): boolean {
  return journeys.length === 0 || journeys.every((journey) => journey.nodes.length === 0);
}

/** Journey katman sırası charter'a uygun mu (Event → Metric → Signal → Context → Observation). */
export function validateTrustTimelineLayerOrder(nodes: TrustTimelineNode[]): boolean {
  let maxLayer = -1;
  let lastEventSort = -Infinity;
  for (const node of nodes) {
    const layer = LAYER_ORDER[node.kind];
    if (layer < maxLayer) return false;
    if (node.kind === 'event') {
      if (node.sortKey < lastEventSort) return false;
      lastEventSort = node.sortKey;
    }
    maxLayer = Math.max(maxLayer, layer);
  }
  return true;
}

/** Determinism / replayability fingerprint. */
export function trustTimelineJourneyFingerprint(journey: TrustTimelineJourney): string {
  return JSON.stringify(
    journey.nodes.map((node) => ({
      kind: node.kind,
      label: node.label,
      detail: node.detail ?? null,
      timestamp: node.timestamp ?? null,
      whyText: node.whyText,
    })),
  );
}
