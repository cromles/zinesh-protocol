/**
 * API kök adresi.
 * - www + app: same-origin /api (app nginx /api → www PHP proxy)
 * - apex (zinesh.com): https://www.zinesh.com (301 POST'u GET'e çevirir)
 * Override: VITE_API_BASE_URL=https://www.zinesh.com
 */
export function apiOrigin(): string {
  const configured = (import.meta.env.VITE_API_BASE_URL as string | undefined)?.trim();
  if (configured) {
    return configured.replace(/\/$/, '');
  }
  if (import.meta.env.DEV) {
    return '';
  }
  if (typeof window !== 'undefined') {
    const host = window.location.hostname;
    if (host === 'www.zinesh.com' || host === 'app.zinesh.com') {
      return '';
    }
    if (host === 'zinesh.com') {
      return 'https://www.zinesh.com';
    }
  }
  return '';
}

export function apiUrl(path: string): string {
  const normalized = path.startsWith('/') ? path : `/${path}`;
  const origin = apiOrigin();
  return origin ? `${origin}${normalized}` : normalized;
}
