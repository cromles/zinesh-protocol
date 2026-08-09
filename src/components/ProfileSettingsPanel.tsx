import React from 'react';
import { ArrowLeft, CheckCircle2, Mail, PlusCircle } from 'lucide-react';
import UserProfilePanel from './UserProfilePanel';
import ProfileNameSection from './ProfileNameSection';
import ProfilePrivacySection from './ProfilePrivacySection';
import SecurityCenterPanel from './SecurityCenterPanel';
import EmailVerificationBanner from './EmailVerificationBanner';
import KycVerificationCard from './KycVerificationCard';
import type { UserProfile } from '../lib/userProfile';

interface ProfileSettingsPanelProps {
  user: Pick<
    UserProfile,
    | 'name'
    | 'email'
    | 'ticketNumber'
    | 'trustScore'
    | 'referralCode'
    | 'kycStatus'
    | 'emailVerified'
    | 'kycVerified'
    | 'role'
    | 'isFounder'
    | 'createdAt'
    | 'uid'
    | 'jobHistoryPublic'
    | 'googleLinked'
    | 'totpEnabled'
    | 'signupRewardAmount'
  >;
  sessionToken?: string;
  onUserUpdate?: (user: UserProfile) => void;
  onPasswordChanged?: () => void;
  onGoToDashboard?: () => void;
  onStartNewAgreement?: () => void;
}

export default function ProfileSettingsPanel({
  user,
  sessionToken,
  onUserUpdate,
  onPasswordChanged,
  onGoToDashboard,
  onStartNewAgreement,
}: ProfileSettingsPanelProps) {
  const emailVerified = Boolean(user.emailVerified);
  const kycStatus = user.kycStatus ?? 'none';
  const showKycForm = !user.isFounder && emailVerified && (kycStatus === 'none' || kycStatus === 'rejected');
  const showKycStatus = !user.isFounder && emailVerified && (kycStatus === 'approved' || kycStatus === 'pending');

  return (
    <div id="console-pane-bilgilerim" className="console-scroll-target space-y-4 text-left">
      {(onGoToDashboard || onStartNewAgreement) && (
    <section className="flex flex-col gap-2 rounded-2xl border border-slate-800 bg-slate-900/80 p-3 sm:flex-row sm:gap-2 sm:p-4" aria-label="Konsol kısayolları">
          {onGoToDashboard && (
            <button
              type="button"
              onClick={onGoToDashboard}
              className="inline-flex min-h-[44px] flex-1 items-center justify-center gap-2 rounded-xl border border-slate-700 bg-slate-950 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:border-emerald-500/40 hover:text-white"
            >
              <ArrowLeft className="h-4 w-4 shrink-0" aria-hidden />
              Genel Bakış
            </button>
          )}
          {onStartNewAgreement && (
            <button
              type="button"
              onClick={onStartNewAgreement}
              className="inline-flex min-h-[44px] flex-1 items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-500 to-teal-500 px-4 py-2.5 text-sm font-bold text-slate-950 transition hover:from-emerald-400 hover:to-teal-400"
            >
              <PlusCircle className="h-4 w-4 shrink-0" aria-hidden />
              Yeni Anlaşma Başlat
            </button>
          )}
        </section>
      )}

      <UserProfilePanel user={user} />

      <ProfileNameSection
        name={user.name || 'Üye'}
        kycStatus={user.kycStatus}
        onUpdated={onUserUpdate}
      />

      <section className="rounded-2xl border border-slate-800 bg-slate-900/80 p-4 sm:p-5">
        <h3 className="mb-3 flex items-center gap-2 text-[10px] font-mono font-bold uppercase tracking-wider text-slate-400">
          <Mail className="h-3.5 w-3.5" />
          E-posta
        </h3>
        <div className="flex flex-wrap items-center gap-2">
          <p className="text-sm text-zinc-100 break-all">{user.email || '—'}</p>
          {emailVerified ? (
            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border text-emerald-300 bg-emerald-500/10 border-emerald-500/25">
              <CheckCircle2 className="w-3 h-3" aria-hidden />
              Doğrulandı
            </span>
          ) : (
            <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border text-amber-200 bg-amber-500/10 border-amber-500/25">
              Doğrulanmadı
            </span>
          )}
        </div>
        <p className="text-[11px] text-zinc-500 mt-2 leading-relaxed">
          E-posta adresi güvenlik nedeniyle değiştirilemez. Bildirimler bu adrese gider.
        </p>
      </section>

      {!emailVerified && user.email && (
        <div className="[&_.mb-6]:mb-0">
          <EmailVerificationBanner
          email={user.email}
          uid={user.uid}
          sessionToken={sessionToken}
          onResent={(updated) => {
            if (updated) onUserUpdate?.(updated);
          }}
          />
        </div>
      )}

      <SecurityCenterPanel
        googleLinked={user.googleLinked}
        totpEnabled={user.totpEnabled}
        onPasswordChanged={onPasswordChanged}
      />

      <ProfilePrivacySection
        jobHistoryPublic={user.jobHistoryPublic}
        onUpdated={(isPublic) => {
          onUserUpdate?.({ ...user, jobHistoryPublic: isPublic } as UserProfile);
        }}
      />

      {showKycStatus && <KycVerificationCard kycStatus={kycStatus} emailVerified={emailVerified} />}

      {showKycForm && (
        <KycVerificationCard
          fullNameDefault={user.name}
          emailVerified={emailVerified}
          kycStatus={kycStatus}
          signupRewardAmount={user.signupRewardAmount}
          onSuccess={(updated) => onUserUpdate?.(updated)}
        />
      )}
    </div>
  );
}
