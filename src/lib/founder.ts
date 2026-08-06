/** Sunucu config.local.php founder_emails ile aynı tutulmalı */
const FOUNDER_EMAILS = ['yasinkarademir147@gmail.com'];

export function isFounderEmail(email?: string | null): boolean {
  const normalized = email?.toLowerCase().trim() ?? '';
  return normalized !== '' && FOUNDER_EMAILS.includes(normalized);
}

export function resolveIsFounder(profile?: { email?: string; isFounder?: boolean } | null): boolean {
  if (!profile) return false;
  if (profile.isFounder) return true;
  return isFounderEmail(profile.email);
}
