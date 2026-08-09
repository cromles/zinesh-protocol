export type RiskConfidence = 'low' | 'medium' | 'high';

export interface RiskObservationContextRef {
  context_id?: string;
  title?: string;
  confidence?: string;
}

export interface RiskObservationSignalFrame {
  signal_id?: string;
  title?: string;
  description?: string;
  confidence?: string;
  metric_sources?: Record<string, unknown>;
  event_sources?: unknown[];
  explanation?: {
    chain?: string;
    signal_id?: string;
    metrics?: Record<string, unknown>;
    events?: unknown[];
  };
}

export interface RiskObservation {
  observation_id: string;
  title: string;
  description: string;
  confidence: RiskConfidence;
  observation_version?: string;
  contexts_used?: RiskObservationContextRef[];
  signals_used?: RiskObservationSignalFrame[];
  metrics_used?: Record<string, unknown>;
  evidence_chain?: {
    chain?: string;
    observation_id?: string;
    contexts?: unknown[];
    signals?: unknown[];
  };
  explainability?: {
    chain?: string;
    observation_id?: string;
    contexts?: RiskObservationContextRef[];
    signals?: RiskObservationSignalFrame[];
    metrics?: Record<string, unknown>;
    model_version?: string;
  };
}

/** Backend Public Response Contract (S4.0) — runtime paketleri olduğu gibi taşınır. */
export interface RiskEnginePublicResponse {
  endpoint_version: string;
  risk_engine_version: string;
  room_id: string;
  actor_id: string | null;
  metrics: Record<string, unknown>;
  signals: Record<string, unknown>;
  contexts: Record<string, unknown>;
  observations: Record<string, unknown>;
}

export interface RiskEngineViewModel extends RiskEnginePublicResponse {
  observationRows: RiskObservation[];
}

export class RiskEngineHttpError extends Error {
  readonly status: number;
  readonly code?: string;

  constructor(message: string, status: number, code?: string) {
    super(message);
    this.name = 'RiskEngineHttpError';
    this.status = status;
    this.code = code;
  }
}

export type RiskEngineErrorKind =
  | 'unauthorized'
  | 'forbidden'
  | 'not_found'
  | 'server'
  | 'network'
  | 'unknown';

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function normalizeConfidence(value: unknown): RiskConfidence {
  const raw = String(value ?? '').toLowerCase();
  if (raw === 'high' || raw === 'medium' || raw === 'low') {
    return raw;
  }
  return 'low';
}

function normalizeObservationRow(row: unknown): RiskObservation | null {
  if (!isRecord(row)) return null;
  const observationId = String(row.observation_id ?? '').trim();
  if (!observationId) return null;
  return {
    observation_id: observationId,
    title: String(row.title ?? ''),
    description: String(row.description ?? ''),
    confidence: normalizeConfidence(row.confidence),
    observation_version: row.observation_version != null ? String(row.observation_version) : undefined,
    contexts_used: Array.isArray(row.contexts_used)
      ? (row.contexts_used as RiskObservationContextRef[])
      : undefined,
    signals_used: Array.isArray(row.signals_used)
      ? (row.signals_used as RiskObservationSignalFrame[])
      : undefined,
    metrics_used: isRecord(row.metrics_used) ? row.metrics_used : undefined,
    evidence_chain: isRecord(row.evidence_chain)
      ? (row.evidence_chain as RiskObservation['evidence_chain'])
      : undefined,
    explainability: isRecord(row.explainability)
      ? (row.explainability as RiskObservation['explainability'])
      : undefined,
  };
}

/** Runtime observation paketinden UI satırlarını çıkarır; yeni intelligence üretmez. */
export function extractObservationRows(observationsPkg: unknown): RiskObservation[] {
  if (!isRecord(observationsPkg)) return [];
  const rows = observationsPkg.observations;
  if (!Array.isArray(rows)) return [];
  return rows
    .map((row) => normalizeObservationRow(row))
    .filter((row): row is RiskObservation => row !== null);
}

/** Backend yanıtını Public Response Contract'a parse eder. */
export function parseRiskEngineResponse(
  data: Record<string, unknown>,
  roomId: string,
): RiskEngineViewModel {
  const metrics = isRecord(data.metrics) ? data.metrics : {};
  const signals = isRecord(data.signals) ? data.signals : {};
  const contexts = isRecord(data.contexts) ? data.contexts : {};
  const observations = isRecord(data.observations) ? data.observations : {};

  return {
    endpoint_version: String(data.endpoint_version ?? ''),
    risk_engine_version: String(data.risk_engine_version ?? ''),
    room_id: String(data.room_id ?? roomId),
    actor_id: data.actor_id == null ? null : String(data.actor_id),
    metrics,
    signals,
    contexts,
    observations,
    observationRows: extractObservationRows(observations),
  };
}

export function riskEngineErrorKind(err: unknown): RiskEngineErrorKind {
  if (err instanceof RiskEngineHttpError) {
    if (err.status === 401) return 'unauthorized';
    if (err.status === 403) return 'forbidden';
    if (err.status === 404) return 'not_found';
    if (err.status >= 500) return 'server';
    return 'unknown';
  }
  if (err instanceof TypeError) return 'network';
  return 'unknown';
}

export function riskEngineErrorMessage(kind: RiskEngineErrorKind): string {
  switch (kind) {
    case 'unauthorized':
      return 'Oturumunuz sona ermiş. Risk Intelligence görüntülemek için tekrar giriş yapın.';
    case 'forbidden':
      return 'Bu oda için Risk Intelligence görüntüleme yetkiniz yok.';
    case 'not_found':
      return 'Oda bulunamadı.';
    case 'server':
      return 'Risk Intelligence şu an yanıt vermiyor. Lütfen daha sonra tekrar deneyin.';
    case 'network':
      return 'Bağlantı kurulamadı. İnternet bağlantınızı kontrol edip tekrar deneyin.';
    default:
      return 'Risk Intelligence yüklenemedi.';
  }
}

/** Evidence Completeness — risk skoru değildir. */
export function riskConfidenceLabel(confidence: RiskConfidence | string): string {
  switch (String(confidence).toLowerCase()) {
    case 'high':
      return 'Yüksek';
    case 'medium':
      return 'Orta';
    case 'low':
      return 'Düşük';
    default:
      return 'Düşük';
  }
}

export function riskConfidenceTone(confidence: RiskConfidence | string): {
  badge: string;
  dot: string;
} {
  switch (String(confidence).toLowerCase()) {
    case 'high':
      return {
        badge: 'text-emerald-300 bg-emerald-500/10 border-emerald-500/25',
        dot: 'bg-emerald-400',
      };
    case 'medium':
      return {
        badge: 'text-amber-200 bg-amber-500/10 border-amber-500/25',
        dot: 'bg-amber-400',
      };
    default:
      return {
        badge: 'text-zinc-400 bg-zinc-800/60 border-zinc-700',
        dot: 'bg-zinc-500',
      };
  }
}
