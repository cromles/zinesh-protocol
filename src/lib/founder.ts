/** Kurucu bayrağı sunucudan gelir (config.local.php → founder_emails / founder_uids). */
export function resolveIsFounder(profile?: { email?: string; isFounder?: boolean } | null): boolean {
  return !!profile?.isFounder;
}
