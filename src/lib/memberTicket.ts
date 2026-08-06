/**
 * Üye ID — kanonik form: 4–6 haneli sayı (ör. 22595).
 * Eski ZN-SH-DUAL-22595 formatı girişte kabul edilir, gösterimde sadece rakam.
 */

export function memberTicketDigits(raw: string): string {
  let t = raw.trim();
  if (!t) return '';
  t = t
    .replace(/[\u200B-\u200D\uFEFF]/g, '')
    .toUpperCase()
    .replace(/[\u2010-\u2015\u2212–—−]/g, '-')
    .replace(/[\s_]+/g, '')
    .replace(/-+/g, '-');

  const legacy = t.match(/^ZN-SH-(?:WEB3|REAL|DUAL)-(\d{4,6})$/);
  if (legacy) return legacy[1];

  const compact = t.match(/^ZNSH(?:WEB3|REAL|DUAL)(\d{4,6})$/);
  if (compact) return compact[1];

  const roleOnly = t.match(/^(?:WEB3|REAL|DUAL)-(\d{4,6})$/);
  if (roleOnly) return roleOnly[1];

  const digits = t.replace(/\D/g, '');
  if (/^\d{4,6}$/.test(digits)) return digits;

  return '';
}

export function displayMemberTicket(raw: string): string {
  return memberTicketDigits(raw) || raw.trim();
}

export function normalizeMemberTicket(raw: string): string {
  return memberTicketDigits(raw);
}
