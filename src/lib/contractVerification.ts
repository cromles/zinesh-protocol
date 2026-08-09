import type { UserProfile } from './userProfile';

export type VerificationTier = 'email' | 'phone' | 'kyc';

/** Geçici QA bypass — backend ile aynı tarih (2026-08-14 00:00 TR). */
const CONTRACT_KYC_GATE_ENABLED_AT = Date.parse('2026-08-14T00:00:00+03:00');

function contractKycGateEnabled(): boolean {
  return Date.now() >= CONTRACT_KYC_GATE_ENABLED_AT;
}

export type ContractVerificationSnapshot = {
  emailVerified: boolean;
  phoneVerified: boolean;
  kycVerified: boolean;
};

export function contractVerificationFromUser(
  user?: Pick<
    UserProfile,
    'emailVerified' | 'phoneVerified' | 'kycVerified' | 'kycStatus' | 'isFounder'
  > | null,
): ContractVerificationSnapshot {
  const emailVerified = Boolean(user?.emailVerified);
  const phoneVerified = Boolean(user?.phoneVerified);
  const kycVerified = user?.isFounder
    ? true
    : user?.kycVerified === true || user?.kycStatus === 'approved';
  return { emailVerified, phoneVerified, kycVerified };
}

export function getMissingContractVerifications(
  snapshot: ContractVerificationSnapshot,
): VerificationTier[] {
  const missing: VerificationTier[] = [];
  if (!snapshot.emailVerified) missing.push('email');
  // Telefon doğrulaması geçici olarak devre dışı.
  // KYC geçici QA bypass — 2026-08-14'e kadar yeni iş kapısında zorunlu değil.
  if (contractKycGateEnabled() && !snapshot.kycVerified) missing.push('kyc');
  return missing;
}

export function canCreateContract(
  user?: Pick<
    UserProfile,
    'emailVerified' | 'phoneVerified' | 'kycVerified' | 'kycStatus' | 'isFounder'
  > | null,
): boolean {
  return getMissingContractVerifications(contractVerificationFromUser(user)).length === 0;
}

export const VERIFICATION_TIER_LABELS: Record<VerificationTier, string> = {
  email: 'E-posta doğrulaması',
  phone: 'Telefon doğrulaması',
  kyc: 'Kimlik (KYC) doğrulaması',
};

export const VERIFICATION_TIER_HINTS: Record<VerificationTier, string> = {
  email: 'Profil → E-posta bölümünden doğrulama kodunu gir.',
  phone: 'Profil → Telefon bölümünden numaranı gir ve SMS kodunu doğrula.',
  kyc: 'Profil → Kimlik doğrulama formunu doldur ve onay bekle.',
};

export const VERIFICATION_PROFILE_ANCHORS: Record<VerificationTier, string> = {
  email: 'email-verify-section',
  phone: 'phone-verify-section',
  kyc: 'kyc-verify-section',
};

export class ContractVerificationError extends Error {
  readonly status: number;
  readonly missing: VerificationTier[];
  readonly snapshot: ContractVerificationSnapshot;

  constructor(message: string, payload: { missing?: string[]; snapshot?: Partial<ContractVerificationSnapshot> }) {
    super(message);
    this.name = 'ContractVerificationError';
    this.status = 403;
    const snap = payload.snapshot ?? {};
    this.snapshot = {
      emailVerified: Boolean(snap.emailVerified),
      phoneVerified: Boolean(snap.phoneVerified),
      kycVerified: Boolean(snap.kycVerified),
    };
    this.missing = (payload.missing ?? [])
      .filter((tier): tier is VerificationTier => tier === 'email' || tier === 'phone' || tier === 'kyc');
  }
}

export function parseContractVerificationError(
  data: Record<string, unknown>,
  fallbackMessage: string,
): ContractVerificationError {
  return new ContractVerificationError(
    typeof data.message === 'string' && data.message.trim() ? data.message.trim() : fallbackMessage,
    {
      missing: Array.isArray(data.missing) ? (data.missing as string[]) : undefined,
      snapshot: {
        emailVerified: data.email_verified === true || data.emailVerified === true,
        phoneVerified: data.phone_verified === true || data.phoneVerified === true,
        kycVerified: data.kyc_verified === true || data.kycVerified === true,
      },
    },
  );
}
