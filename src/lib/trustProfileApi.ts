import { getSessionToken } from './auth';
import { apiUrl } from './apiBase';

export type EscrowJobStatus = 'pending_match' | 'active' | 'completion_pending' | 'success' | 'cancelled' | 'failed' | 'disputed';

export interface EscrowJob {
  id: string;
  matchCode?: string;
  title: string;
  description: string;
  category: string;
  value: number;
  status: EscrowJobStatus;
  fundsLocked?: boolean;
  lockedValue?: number;
  buyerName: string;
  supplierName: string;
  deliveryDate: string;
  createdAt: string;
  matchedAt?: string;
  activatedAt?: string;
  completedAt: string;
  commission?: number | null;
  payout?: number | null;
  myRole?: 'buyer' | 'supplier' | null;
  supplierEmail?: string;
  buyerConfirmedComplete?: boolean;
  supplierConfirmedComplete?: boolean;
  buyerCancelRequested?: boolean;
  supplierCancelRequested?: boolean;
}

export interface EscrowJobStats {
  active: number;
  completed: number;
  unsuccessful: number;
}

export interface TrustProfile {
  uid: string;
  name: string;
  trustScore: number;
  jobHistoryPublic: boolean;
  referralCode?: string;
  kycApproved?: boolean;
  emailVerified?: boolean;
  stats: EscrowJobStats;
  expertise?: string[];
  jobs?: EscrowJob[];
  jobsHidden?: boolean;
}

export async function fetchMyTrustProfile(): Promise<TrustProfile> {
  const sessionToken = getSessionToken();
  if (!sessionToken) throw new Error('Oturum gerekli.');
  const res = await fetch(apiUrl('/api/trust_profile.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ action: 'mine', sessionToken }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.profile) {
    throw new Error(data.message || 'Profil yüklenemedi.');
  }
  return data.profile as TrustProfile;
}

export async function fetchPublicTrustProfile(opts: {
  uid?: string;
  referralCode?: string;
}): Promise<TrustProfile> {
  const params = new URLSearchParams({ action: 'public' });
  if (opts.uid) params.set('uid', opts.uid);
  if (opts.referralCode) params.set('ref', opts.referralCode.trim().toUpperCase());
  const res = await fetch(apiUrl(`/api/trust_profile.php?${params.toString()}`));
  const data = await res.json().catch(() => ({}));
  if (!res.ok || !data.profile) {
    throw new Error(data.message || 'Profil bulunamadı.');
  }
  return data.profile as TrustProfile;
}

export async function setJobHistoryVisibility(isPublic: boolean): Promise<{
  jobHistoryPublic: boolean;
  message: string;
}> {
  const sessionToken = getSessionToken();
  if (!sessionToken) throw new Error('Oturum gerekli.');
  const res = await fetch(apiUrl('/api/trust_profile.php'), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      action: 'set_job_history_visibility',
      sessionToken,
      public: isPublic,
    }),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw new Error(data.message || 'Tercih kaydedilemedi.');
  }
  return {
    jobHistoryPublic: Boolean(data.jobHistoryPublic),
    message: (data.message as string) || 'Kaydedildi.',
  };
}

export function escrowJobStatusLabel(status: EscrowJobStatus): string {
  switch (status) {
    case 'pending_match':
      return 'Eşleşme bekleniyor';
    case 'active':
      return 'Kilitli';
    case 'completion_pending':
      return 'Onay bekleniyor';
    case 'success':
      return 'Tamamlandı';
    case 'cancelled':
      return 'İptal';
    case 'failed':
      return 'Başarısız';
    case 'disputed':
      return 'Uyuşmazlık';
    default:
      return status;
  }
}

export function isRealEscrowJobId(id: string): boolean {
  return /^ESC-[A-F0-9]{10}$/i.test(id.trim());
}

export function isEscrowMatchCode(code: string): boolean {
  return /^ZN-[A-Z0-9]{6}$/i.test(code.trim());
}

export function isHistoryEscrowJob(job: EscrowJob): boolean {
  return !['active', 'pending_match'].includes(job.status);
}
