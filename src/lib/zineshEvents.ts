/**
 * Frontend → sunucu olay telemetrisi.
 * Production static build: relative /api/... kullanılmaz; URL yoksa no-op.
 */

import { apiUrl } from './apiBase';

export type ZineshEventName =
  | 'app_loaded'
  | 'landing_view'
  | 'console_open'
  | 'register_success'
  | 'login_success'
  | 'deposit_success'
  | 'swap_success'
  | 'referral_landing'
  | 'client_error';

export interface ZineshEventPayload {
  [key: string]: string | number | boolean | null | undefined;
}

function resolveEventEndpoint(): string | null {
  const configured = (import.meta.env.VITE_ZINESH_EVENT_WEBHOOK_URL as string | undefined)?.trim();
  if (configured) {
    return configured;
  }
  if (import.meta.env.DEV) {
    return apiUrl('/api/events.php');
  }
  if (typeof window !== 'undefined' && window.location.hostname === 'app.zinesh.com') {
    return apiUrl('/api/events.php');
  }
  return null;
}

/** Production’da URL yoksa sessizce atlanır. */
export function emitZineshEvent(name: ZineshEventName, payload: ZineshEventPayload = {}): void {
  const endpoint = resolveEventEndpoint();
  if (!endpoint) {
    return;
  }

  const body = JSON.stringify({
    event: name,
    payload,
    path: typeof window !== 'undefined' ? window.location.pathname : '',
    ts: Date.now(),
  });

  try {
    if (typeof navigator !== 'undefined' && typeof navigator.sendBeacon === 'function') {
      const blob = new Blob([body], { type: 'application/json' });
      if (navigator.sendBeacon(endpoint, blob)) {
        return;
      }
    }
  } catch {
    // sendBeacon başarısızsa fetch'e düş
  }

  void fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body,
    keepalive: true,
    credentials: 'omit',
  }).catch(() => {
    // Telemetri hatası kullanıcı akışını bozmamalı
  });
}
