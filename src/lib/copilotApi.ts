import { getSessionToken } from './auth';
import { apiUrl } from './apiBase';
import {
  CopilotHttpError,
  parseCopilotResponse,
  type CopilotIntent,
  type CopilotResponse,
} from './copilotLogic';

export {
  CopilotHttpError,
  parseCopilotResponse,
  copilotIntentLabel,
  copilotUserMessage,
  copilotAssistantMessage,
  copilotPreservesRiskEngineContract,
  copilotResponseFingerprint,
  copilotFrameworkFieldsPresent,
  copilotExplanationIsAdvisoryOnly,
  COPILOT_EXPLAINABILITY_CHAIN,
  COPILOT_HUMAN_DISCLAIMER,
} from './copilotLogic';

export type { CopilotIntent, CopilotResponse, CopilotConversationMessage } from './copilotLogic';

const COPILOT_API = apiUrl('/api/copilot.php');

export async function fetchCopilotExplanation(
  roomId: string,
  intent: CopilotIntent,
  observationId?: string,
  actorId?: string,
): Promise<CopilotResponse> {
  const sessionToken = getSessionToken()?.trim();
  const headers: Record<string, string> = {};
  if (sessionToken) {
    headers.Authorization = `Bearer ${sessionToken}`;
    headers['X-Session-Token'] = sessionToken;
  }

  const params = new URLSearchParams();
  params.set('room_id', roomId);
  params.set('intent', intent);
  if (observationId?.trim()) {
    params.set('observation_id', observationId.trim());
  }
  if (sessionToken) {
    params.set('sessionToken', sessionToken);
  }
  if (actorId?.trim()) {
    params.set('actor_id', actorId.trim());
  }

  let res: Response;
  try {
    res = await fetch(`${COPILOT_API}?${params.toString()}`, {
      method: 'GET',
      credentials: 'include',
      headers,
    });
  } catch {
    throw new CopilotHttpError('Bağlantı kurulamadı.', 0);
  }

  const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
  if (!res.ok) {
    throw new CopilotHttpError(
      (typeof data.message === 'string' && data.message) || 'Copilot yanıtı alınamadı.',
      res.status,
    );
  }

  return parseCopilotResponse(data, roomId);
}
