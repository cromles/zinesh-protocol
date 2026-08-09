export type EscrowDebugEntry = {
  action: string;
  httpStatus: number;
  responseSummary: string;
  ok: boolean;
  at: string;
};

type Listener = () => void;

let lastCall: EscrowDebugEntry | null = null;
let lastError: string | null = null;
const listeners = new Set<Listener>();

function notify(): void {
  listeners.forEach((fn) => fn());
}

export function recordEscrowApiCall(
  action: string,
  httpStatus: number,
  payload: Record<string, unknown>,
  ok: boolean,
): void {
  const room = payload.room as Record<string, unknown> | undefined;
  const status = typeof room?.status === 'string' ? room.status : '';
  const message = typeof payload.message === 'string' ? payload.message : '';
  const responseSummary = status || message || (ok ? 'ok' : 'error');

  lastCall = {
    action,
    httpStatus,
    responseSummary,
    ok,
    at: new Date().toISOString(),
  };
  if (!ok) {
    lastError = message || responseSummary;
  } else {
    lastError = null;
  }
  notify();
}

export function recordEscrowApiError(action: string, message: string): void {
  lastCall = {
    action,
    httpStatus: 0,
    responseSummary: message,
    ok: false,
    at: new Date().toISOString(),
  };
  lastError = message;
  notify();
}

export function getEscrowDebugSnapshot(): {
  lastCall: EscrowDebugEntry | null;
  lastError: string | null;
} {
  return { lastCall, lastError };
}

export function subscribeEscrowDebug(listener: Listener): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
