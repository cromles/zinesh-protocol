import React, { useState } from 'react';
import { Shield, CheckCircle2, RefreshCw, AlertCircle, Clock } from 'lucide-react';
import { motion } from 'motion/react';
import { submitKyc } from '../lib/auth';
import { rejectObviousFakeNumber } from '../lib/numberValidation';
import type { UserProfile } from '../lib/userProfile';

interface KycVerificationCardProps {
  fullNameDefault?: string;
  kycStatus?: string;
  signupRewardAmount?: number;
  emailVerified?: boolean;
  onSuccess?: (user: UserProfile) => void;
  compact?: boolean;
  onOpenFullForm?: () => void;
  /** Profil özeti satırında küçük rozet */
  badgeOnly?: boolean;
}

export function KycStatusBadge({ kycStatus = 'none' }: { kycStatus?: string }) {
  if (kycStatus === 'approved') {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border text-emerald-300 bg-emerald-500/10 border-emerald-500/25">
        <CheckCircle2 className="w-3 h-3" aria-hidden />
        Doğrulandı
      </span>
    );
  }
  if (kycStatus === 'pending') {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border text-amber-200 bg-amber-500/10 border-amber-500/25">
        <Clock className="w-3 h-3" aria-hidden />
        İnceleniyor
      </span>
    );
  }
  if (kycStatus === 'rejected') {
    return (
      <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border text-red-300 bg-red-500/10 border-red-500/25">
        Reddedildi
      </span>
    );
  }
  return (
    <span className="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border text-zinc-400 bg-zinc-800/80 border-zinc-700">
      Doğrulanmadı
    </span>
  );
}

export default function KycVerificationCard({
  fullNameDefault = '',
  kycStatus = 'none',
  emailVerified = true,
  onSuccess,
  compact = false,
  onOpenFullForm,
  badgeOnly = false,
}: KycVerificationCardProps) {
  const [fullName, setFullName] = useState(fullNameDefault);
  const [nationalId, setNationalId] = useState('');
  const [birthDate, setBirthDate] = useState('');
  const [phone, setPhone] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const approved = kycStatus === 'approved';
  const pending = kycStatus === 'pending';
  const rejected = kycStatus === 'rejected';

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!emailVerified) {
      setError('Önce e-postanı doğrula.');
      return;
    }
    const fakeId = rejectObviousFakeNumber(nationalId);
    if (fakeId) {
      setError(fakeId);
      return;
    }
    const fakePhone = rejectObviousFakeNumber(phone);
    if (fakePhone) {
      setError(fakePhone);
      return;
    }
    setSubmitting(true);
    setMessage('');
    setError('');
    try {
      const result = await submitKyc({ fullName, nationalId, birthDate, phone });
      setMessage(result.message);
      onSuccess?.(result.user);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'KYC gönderilemedi.');
    } finally {
      setSubmitting(false);
    }
  };

  if (badgeOnly) {
    return <KycStatusBadge kycStatus={kycStatus} />;
  }

  if (approved) {
    return (
      <div
        id="kyc-verify-section"
        className="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 flex items-center gap-3"
      >
        <CheckCircle2 className="h-5 w-5 text-emerald-400 shrink-0" />
        <div className="text-left min-w-0">
          <p className="text-sm font-semibold text-emerald-200">Kimlik doğrulandı</p>
          <p className="text-[11px] text-emerald-300/70 mt-0.5">Hesabın KYC onaylı.</p>
        </div>
      </div>
    );
  }

  if (pending) {
    return (
      <div
        id="kyc-verify-section"
        className="rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3 flex items-center gap-3"
      >
        <Clock className="h-5 w-5 text-amber-400 shrink-0" />
        <div className="text-left min-w-0">
          <p className="text-sm font-semibold text-amber-100">KYC inceleniyor</p>
          <p className="text-[11px] text-amber-200/70 mt-0.5">Başvurun alındı. Onay sonrası bildirim alırsın.</p>
        </div>
      </div>
    );
  }

  if (compact) {
    return (
      <div
        id="kyc-verify-section"
        className="rounded-2xl border border-purple-500/30 bg-purple-500/[0.06] p-4 sm:p-5 text-left"
      >
        <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
          <div className="flex items-start gap-3">
            <div className="h-10 w-10 rounded-xl bg-purple-500/15 border border-purple-500/30 flex items-center justify-center shrink-0">
              <Shield className="h-5 w-5 text-purple-300" />
            </div>
            <div>
              <p className="font-display text-sm font-bold text-white">Kimlik doğrulama</p>
              <p className="text-xs text-zinc-400 mt-1">Bilgilerini gir — onay sonrası hesabın güçlenir.</p>
            </div>
          </div>
          <button
            type="button"
            onClick={onOpenFullForm}
            className="shrink-0 px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-xs font-bold transition cursor-pointer"
          >
            Formu aç
          </button>
        </div>
      </div>
    );
  }

  return (
    <motion.div
      id="kyc-verify-section"
      initial={{ opacity: 0, y: -6 }}
      animate={{ opacity: 1, y: 0 }}
      className="rounded-2xl border border-purple-500/35 bg-gradient-to-r from-purple-500/[0.08] via-purple-500/[0.04] to-transparent p-4 sm:p-5"
    >
      <div className="flex items-start gap-3 mb-4 text-left">
        <div className="h-11 w-11 rounded-xl bg-purple-500/15 border border-purple-500/30 flex items-center justify-center shrink-0">
          <Shield className="h-5 w-5 text-purple-300" />
        </div>
        <div>
          <p className="font-mono text-[9px] text-purple-400/90 uppercase tracking-[0.18em] font-bold mb-1">
            Kimlik doğrulama
          </p>
          <h3 className="font-display text-base font-black text-white leading-tight">KYC başvurusu</h3>
          <p className="text-xs text-zinc-400 mt-1">
            {rejected
              ? 'Önceki başvurun reddedildi. Bilgilerini kontrol edip tekrar gönderebilirsin.'
              : 'Bilgilerin güvenli saklanır. Doğrulama hesabını güçlendirir.'}
          </p>
        </div>
      </div>

      {!emailVerified && (
        <div className="mb-4 flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-xs text-amber-200">
          <AlertCircle className="h-4 w-4 shrink-0" />
          Önce e-posta doğrulamasını tamamla.
        </div>
      )}

      <form onSubmit={handleSubmit} className="space-y-3 text-left">
        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <label className="sm:col-span-2 block">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider">Ad Soyad</span>
            <input
              type="text"
              value={fullName}
              onChange={(e) => setFullName(e.target.value)}
              required
              minLength={3}
              className="mt-1 w-full px-3 py-2.5 rounded-xl bg-zinc-950 border border-zinc-800 text-white text-sm focus:border-purple-500/50 focus:outline-none"
              placeholder="Kimlikteki ad soyad"
            />
          </label>
          <label className="block">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider">T.C. Kimlik No</span>
            <input
              type="text"
              inputMode="numeric"
              value={nationalId}
              onChange={(e) => setNationalId(e.target.value.replace(/\D/g, '').slice(0, 11))}
              required
              maxLength={11}
              className="mt-1 w-full px-3 py-2.5 rounded-xl bg-zinc-950 border border-zinc-800 text-white text-sm font-mono focus:border-purple-500/50 focus:outline-none"
              placeholder="11 haneli"
            />
          </label>
          <label className="block">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider">Doğum Tarihi</span>
            <input
              type="date"
              value={birthDate}
              onChange={(e) => setBirthDate(e.target.value)}
              required
              className="mt-1 w-full px-3 py-2.5 rounded-xl bg-zinc-950 border border-zinc-800 text-white text-sm focus:border-purple-500/50 focus:outline-none"
            />
          </label>
          <label className="sm:col-span-2 block">
            <span className="text-[10px] font-mono text-zinc-400 uppercase tracking-wider">Telefon</span>
            <input
              type="tel"
              inputMode="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value.replace(/\D/g, '').slice(0, 11))}
              required
              className="mt-1 w-full px-3 py-2.5 rounded-xl bg-zinc-950 border border-zinc-800 text-white text-sm font-mono focus:border-purple-500/50 focus:outline-none"
              placeholder="05XX XXX XX XX"
            />
          </label>
        </div>

        <p className="text-[10px] text-zinc-500">Bilgiler yalnızca kimlik doğrulama için kullanılır.</p>

        <button
          type="submit"
          disabled={submitting || !emailVerified}
          className="w-full inline-flex items-center justify-center gap-2 px-5 py-3.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-sm font-bold transition disabled:opacity-50 cursor-pointer min-h-[48px]"
        >
          {submitting ? <RefreshCw className="h-4 w-4 animate-spin" /> : <Shield className="h-4 w-4" />}
          {submitting ? 'Gönderiliyor...' : 'KYC Başvurusunu Gönder'}
        </button>
      </form>

      {(message || error) && (
        <p className={`mt-3 text-xs font-medium text-left ${error ? 'text-red-400' : 'text-emerald-400'}`}>
          {error || message}
        </p>
      )}
    </motion.div>
  );
}
