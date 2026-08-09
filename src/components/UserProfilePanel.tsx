import React, { useCallback, useState } from 'react';
import { CheckCircle2, Copy, Mail, Shield, User as UserIcon } from 'lucide-react';
import { KycStatusBadge } from './KycVerificationCard';
import type { UserProfile } from '../lib/userProfile';

interface UserProfilePanelProps {
  user: Pick<
    UserProfile,
    | 'name'
    | 'email'
    | 'ticketNumber'
    | 'trustScore'
    | 'referralCode'
    | 'kycStatus'
    | 'emailVerified'
    | 'role'
    | 'isFounder'
    | 'createdAt'
    | 'uid'
  >;
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return 'Z';
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[1][0]).toUpperCase();
}

function roleLabel(role?: string): string {
  if (role === 'web3') return 'Web3';
  if (role === 'dual') return 'Hibrit';
  if (role === 'real') return 'Standart';
  return '—';
}

function kycLabel(status?: string): { text: string; className: string } | null {
  if (status === 'approved' || status === 'pending' || status === 'rejected') return null;
  return { text: 'Doğrulanmadı', className: 'text-zinc-400 bg-zinc-800/80 border-zinc-700' };
}

function formatDate(iso?: string): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleDateString('tr-TR', { day: 'numeric', month: 'long', year: 'numeric' });
}

function InfoRow({
  label,
  value,
  mono,
  copyValue,
}: {
  label: string;
  value: React.ReactNode;
  mono?: boolean;
  copyValue?: string;
}) {
  const [copied, setCopied] = useState(false);

  const handleCopy = useCallback(async () => {
    if (!copyValue) return;
    try {
      await navigator.clipboard.writeText(copyValue);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 2000);
    } catch {
      /* ignore */
    }
  }, [copyValue]);

  return (
    <div className="flex flex-col gap-1 border-b border-slate-800/80 py-3 last:border-0 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
      <span className="shrink-0 text-[11px] font-mono uppercase tracking-wider text-slate-500">{label}</span>
      <div className="flex min-w-0 items-center gap-2 sm:justify-end">
        <span
          className={`break-all text-left text-sm text-slate-100 sm:text-right ${mono ? 'font-mono text-[13px]' : ''}`}
        >
          {value}
        </span>
        {copyValue && (
          <button
            type="button"
            onClick={() => void handleCopy()}
            className="shrink-0 touch-target inline-flex items-center justify-center w-8 h-8 rounded-lg border border-zinc-700 bg-zinc-900/80 text-zinc-400 hover:text-zinc-100 hover:border-zinc-600 cursor-pointer"
            title="Kopyala"
            aria-label={`${label} kopyala`}
          >
            {copied ? (
              <CheckCircle2 className="w-3.5 h-3.5 text-emerald-400" aria-hidden />
            ) : (
              <Copy className="w-3.5 h-3.5" aria-hidden />
            )}
          </button>
        )}
      </div>
    </div>
  );
}

export default function UserProfilePanel({ user }: UserProfilePanelProps) {
  const name = user.name?.trim() || 'Üye';
  const email = user.email?.trim() || '—';
  const ticket = user.ticketNumber?.trim() || '—';
  const trust = typeof user.trustScore === 'number' ? user.trustScore : null;
  const kyc = kycLabel(user.kycStatus);
  const emailOk = Boolean(user.emailVerified);

  return (
    <section
      id="user-profile-panel"
      className="overflow-hidden rounded-2xl border border-slate-800 bg-slate-900/80 text-left"
      aria-labelledby="user-profile-heading"
    >
      <div className="border-b border-slate-800/80 bg-gradient-to-r from-emerald-500/10 via-slate-900 to-transparent px-4 py-4 sm:px-6 sm:py-5">
        <div className="flex items-start gap-3 sm:gap-4">
          <div
            className="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl border border-emerald-500/30 bg-gradient-to-br from-emerald-500/20 to-teal-700/20 text-base font-bold text-emerald-300 sm:h-14 sm:w-14 sm:text-lg"
            aria-hidden
          >
            {initials(name)}
          </div>
          <div className="min-w-0 flex-1">
            <p className="text-[10px] font-mono uppercase tracking-wider text-emerald-400">Profil</p>
            <h2 id="user-profile-heading" className="mt-0.5 truncate font-display text-lg font-bold text-white sm:text-xl">
              {name}
            </h2>
            <div className="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-[11px] text-slate-400">
              {ticket !== '—' && (
                <span className="font-mono text-emerald-400/90">ZN-{ticket}</span>
              )}
              {trust !== null && (
                <span className="inline-flex items-center gap-1 tabular-nums">
                  <Shield className="h-3 w-3 text-sky-400" aria-hidden />
                  Güven: {trust}/100
                </span>
              )}
            </div>
            <p className="mt-1.5 hidden text-xs text-zinc-400 sm:flex sm:items-center sm:gap-1.5">
              <Mail className="h-3.5 w-3.5 shrink-0" aria-hidden />
              <span className="truncate">{email}</span>
            </p>
            <div className="flex flex-wrap gap-2 mt-2">
              {emailOk ? (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border text-emerald-300 bg-emerald-500/10 border-emerald-500/25">
                  <CheckCircle2 className="w-3 h-3" aria-hidden />
                  E-posta doğrulandı
                </span>
              ) : (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border text-amber-200 bg-amber-500/10 border-amber-500/25">
                  E-posta doğrulanmadı
                </span>
              )}
              {user.isFounder && (
                <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border text-amber-200 bg-amber-500/10 border-amber-500/30">
                  Kurucu
                </span>
              )}
            </div>
          </div>
        </div>
      </div>

      <div className="px-4 sm:px-6 py-2">
        <InfoRow label="Üye numarası" value={ticket} mono copyValue={ticket !== '—' ? ticket : undefined} />
        <InfoRow label="Hesap türü" value={roleLabel(user.role)} />
        <InfoRow
          label="Kimlik doğrulama"
          value={
            kyc.text ? (
              <span className={`inline-flex px-2 py-0.5 rounded-full text-[11px] font-semibold border ${kyc.className}`}>
                {kyc.text}
              </span>
            ) : (
              <KycStatusBadge kycStatus={user.kycStatus} />
            )
          }
        />
        {trust !== null && (
          <InfoRow
            label="Güven puanı"
            value={
              <span className="inline-flex items-center gap-2">
                <Shield className="w-3.5 h-3.5 text-sky-400 shrink-0" aria-hidden />
                <span className="font-semibold tabular-nums">{trust}</span>
                <span className="text-zinc-500 text-xs">/ 100</span>
              </span>
            }
          />
        )}
        {user.referralCode && (
          <InfoRow
            label="Davet kodun"
            value={user.referralCode}
            mono
            copyValue={user.referralCode}
          />
        )}
        <InfoRow label="Kayıt tarihi" value={formatDate(user.createdAt)} />
      </div>

      <div className="border-t border-slate-800/80 bg-slate-950/40 px-4 py-4 sm:px-6">
        <p className="flex items-start gap-2 text-[11px] leading-relaxed text-slate-500">
          <UserIcon className="w-3.5 h-3.5 shrink-0 mt-0.5" aria-hidden />
          Üye numaranı emanet işlemlerinde karşı tarafa paylaş. Kişisel bilgilerin yalnızca kimlik doğrulama ve
          güvenlik için kullanılır.
        </p>
      </div>
    </section>
  );
}
