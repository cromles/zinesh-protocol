import { getSessionToken } from './auth';
import { apiUrl } from './apiBase';
import {
  RiskEngineHttpError,
  parseRiskEngineResponse,
  riskEngineErrorKind,
  riskEngineErrorMessage,
  type RiskEngineErrorKind,
  type RiskEnginePublicResponse,
  type RiskEngineViewModel,
  type RiskConfidence,
  type RiskObservation,
  type RiskObservationContextRef,
  type RiskObservationSignalFrame,
} from './riskEngineLogic';

export {
  RiskEngineHttpError,
  parseRiskEngineResponse,
  extractObservationRows,
  riskEngineErrorKind,
  riskEngineErrorMessage,
  riskConfidenceLabel,
  riskConfidenceTone,
} from './riskEngineLogic';

export type {
  RiskEngineErrorKind,
  RiskEnginePublicResponse,
  RiskEngineViewModel,
  RiskConfidence,
  RiskObservation,
  RiskObservationContextRef,
  RiskObservationSignalFrame,
};

const RISK_ENGINE_API = apiUrl('/api/risk_engine.php');

export async function fetchRoomRiskEngine(
  roomId: string,
  actorId?: string,
): Promise<RiskEngineViewModel> {
  const sessionToken = getSessionToken()?.trim();
  const headers: Record<string, string> = {};
  if (sessionToken) {
    headers.Authorization = `Bearer ${sessionToken}`;
    headers['X-Session-Token'] = sessionToken;
  }

  const params = new URLSearchParams();
  params.set('room_id', roomId);
  if (sessionToken) {
    params.set('sessionToken', sessionToken);
  }
  if (actorId?.trim()) {
    params.set('actor_id', actorId.trim());
  }

  let res: Response;
  try {
    res = await fetch(`${RISK_ENGINE_API}?${params.toString()}`, {
      method: 'GET',
      credentials: 'include',
      headers,
    });
  } catch {
    throw new RiskEngineHttpError('Bağlantı kurulamadı.', 0);
  }

  const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
  if (!res.ok) {
    const message =
      (typeof data.message === 'string' && data.message) ||
      riskEngineErrorMessage(riskEngineErrorKind(new RiskEngineHttpError('', res.status)));
    throw new RiskEngineHttpError(
      message,
      res.status,
      typeof data.code === 'string' ? data.code : undefined,
    );
  }

  return parseRiskEngineResponse(data, roomId);
}
