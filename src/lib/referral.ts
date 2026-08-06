const SITE_ORIGIN = 'https://www.zinesh.com';

export function normalizeReferralCode(code: string): string {
  return code.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
}

export function referralInviteUrl(code: string): string {
  const normalized = normalizeReferralCode(code);
  const origin = typeof window !== 'undefined' ? window.location.origin : SITE_ORIGIN;
  return `${origin}/?ref=${encodeURIComponent(normalized)}#kayit`;
}

export const REFERRAL_STORAGE_KEY = 'zinesh_pending_ref';

export function storePendingReferralCode(code: string): void {
  const normalized = normalizeReferralCode(code);
  if (!normalized) return;
  try {
    sessionStorage.setItem(REFERRAL_STORAGE_KEY, normalized);
  } catch {
    /* ignore */
  }
}

export function readPendingReferralCode(): string {
  try {
    return normalizeReferralCode(sessionStorage.getItem(REFERRAL_STORAGE_KEY) || '');
  } catch {
    return '';
  }
}

export function clearPendingReferralCode(): void {
  try {
    sessionStorage.removeItem(REFERRAL_STORAGE_KEY);
  } catch {
    /* ignore */
  }
}

export function referralCodeFromUid(uid?: string | null): string {
  if (!uid) return '';
  return normalizeReferralCode(uid.slice(0, 8));
}

export function resolveReferralCode(opts: {
  referralCode?: string | null;
  campaignReferralCode?: string | null;
  uid?: string | null;
}): string {
  const direct = normalizeReferralCode(opts.referralCode || '');
  if (direct) return direct;
  const fromCampaign = normalizeReferralCode(opts.campaignReferralCode || '');
  if (fromCampaign) return fromCampaign;
  return referralCodeFromUid(opts.uid);
}
