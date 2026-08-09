import type { RiskEnginePublicResponse } from './riskEngineLogic';

export type CopilotIntent =
  | 'overview'
  | 'why_observation'
  | 'evidence'
  | 'context_chain'
  | 'signal_chain'
  | 'event_chain';

export const COPILOT_EXPLAINABILITY_CHAIN =
  'risk_observation → context → signal → metric → event';

export const COPILOT_HUMAN_DISCLAIMER =
  'Bu açıklama yalnızca bilgilendirme amaçlıdır. Karar, ceza veya işlem önerisi içermez; nihai karar size aittir.';

export interface CopilotResponse {
  copilot_version: string;
  framework_version: string;
  endpoint_version: string;
  provider: string;
  validation_status: 'pass' | 'rejected' | string;
  validation_reasons: string[];
  room_id: string;
  actor_id: string | null;
  intent: CopilotIntent;
  observation_id: string | null;
  explainability_chain: string;
  explanation: string;
  sources: Record<string, unknown>;
  copilot_context?: Record<string, unknown>;
  human_disclaimer: string;
  risk_engine: RiskEnginePublicResponse;
}

export interface CopilotConversationMessage {
  id: string;
  role: 'user' | 'copilot';
  intent: CopilotIntent;
  observationId: string | null;
  text: string;
  explainabilityChain: string;
}

export class CopilotHttpError extends Error {
  readonly status: number;

  constructor(message: string, status: number) {
    super(message);
    this.name = 'CopilotHttpError';
    this.status = status;
  }
}

const INTENT_LABELS: Record<CopilotIntent, string> = {
  overview: 'Bu odadaki gözlemleri özetle',
  why_observation: 'Bu gözlem neden oluştu?',
  evidence: 'Bu gözlem hangi kanıtlara dayanıyor?',
  context_chain: 'Bu Context neden seçildi?',
  signal_chain: 'Hangi Signal bunu tetikledi?',
  event_chain: 'Hangi Event\'ler bunu oluşturdu?',
};

export function copilotIntentLabel(intent: CopilotIntent): string {
  return INTENT_LABELS[intent] ?? intent;
}

export function parseCopilotResponse(
  data: Record<string, unknown>,
  roomId: string,
): CopilotResponse {
  const riskEngine = (data.risk_engine as Record<string, unknown>) ?? {};
  return {
    copilot_version: String(data.copilot_version ?? ''),
    framework_version: String(data.framework_version ?? '1.0'),
    endpoint_version: String(data.endpoint_version ?? ''),
    provider: String(data.provider ?? 'mock'),
    validation_status: String(data.validation_status ?? 'pass'),
    validation_reasons: Array.isArray(data.validation_reasons)
      ? (data.validation_reasons as string[])
      : [],
    room_id: String(data.room_id ?? roomId),
    actor_id: data.actor_id == null ? null : String(data.actor_id),
    intent: String(data.intent ?? 'overview') as CopilotIntent,
    observation_id: data.observation_id == null ? null : String(data.observation_id),
    explainability_chain: String(
      data.explainability_chain ?? COPILOT_EXPLAINABILITY_CHAIN,
    ),
    explanation: String(data.explanation ?? ''),
    sources: (data.sources as Record<string, unknown>) ?? {},
    copilot_context: (data.copilot_context as Record<string, unknown>) ?? undefined,
    human_disclaimer: String(data.human_disclaimer ?? COPILOT_HUMAN_DISCLAIMER),
    risk_engine: {
      endpoint_version: String(riskEngine.endpoint_version ?? ''),
      risk_engine_version: String(riskEngine.risk_engine_version ?? ''),
      room_id: String(riskEngine.room_id ?? roomId),
      actor_id: riskEngine.actor_id == null ? null : String(riskEngine.actor_id),
      metrics: (riskEngine.metrics as Record<string, unknown>) ?? {},
      signals: (riskEngine.signals as Record<string, unknown>) ?? {},
      contexts: (riskEngine.contexts as Record<string, unknown>) ?? {},
      observations: (riskEngine.observations as Record<string, unknown>) ?? {},
    },
  };
}

export function copilotUserMessage(
  intent: CopilotIntent,
  observationId: string | null,
): CopilotConversationMessage {
  return {
    id: `user-${intent}-${observationId ?? 'all'}-${Date.now()}`,
    role: 'user',
    intent,
    observationId,
    text: copilotIntentLabel(intent),
    explainabilityChain: COPILOT_EXPLAINABILITY_CHAIN,
  };
}

export function copilotAssistantMessage(response: CopilotResponse): CopilotConversationMessage {
  return {
    id: `copilot-${response.intent}-${response.observation_id ?? 'all'}-${Date.now()}`,
    role: 'copilot',
    intent: response.intent,
    observationId: response.observation_id,
    text: response.explanation,
    explainabilityChain: response.explainability_chain,
  };
}

/** Risk Engine public paketi değiştirilmeden taşınıyor mu? */
export function copilotPreservesRiskEngineContract(
  before: Record<string, unknown>,
  after: CopilotResponse,
): boolean {
  const engine = before.risk_engine;
  if (!engine || typeof engine !== 'object') return false;
  return JSON.stringify(engine) === JSON.stringify(after.risk_engine);
}

/** Determinism fingerprint */
export function copilotResponseFingerprint(response: CopilotResponse): string {
  return JSON.stringify({
    intent: response.intent,
    observation_id: response.observation_id,
    explanation: response.explanation,
    explainability_chain: response.explainability_chain,
    provider: response.provider,
    validation_status: response.validation_status,
    risk_engine: response.risk_engine,
  });
}

export function copilotFrameworkFieldsPresent(response: CopilotResponse): boolean {
  return (
    response.framework_version === '1.0' &&
    response.provider === 'mock' &&
    response.validation_status === 'pass'
  );
}

/** Yasak içerik kontrolü (Human in Control) */
export function copilotExplanationIsAdvisoryOnly(text: string): boolean {
  const forbidden = [
    /risk\s*score/i,
    /tavsiye\s*ed/i,
    /önerir/i,
    /settlement\s*öner/i,
    /ceza\s*öner/i,
    /fraud/i,
    /otomatik\s*karar/i,
  ];
  return !forbidden.some((pattern) => pattern.test(text));
}
