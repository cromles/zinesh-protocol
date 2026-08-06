import React, { useEffect, useState } from 'react';
import { Mail, Gift, CheckCircle2, RefreshCw, ChevronRight, Sparkles, KeyRound } from 'lucide-react';
import { motion } from 'motion/react';
import { resendVerificationEmail, verifyEmailCode } from '../lib/auth';

interface EmailVerificationBannerProps {
  email: string;
  uid?: string;
  sessionToken?: string;
  onResent?: (user?: import('../lib/firebase').UserProfile) => void;
}

const RESEND_COOLDOWN_SEC = 60;

export default function EmailVerificationBanner({
  email,
  uid,
  sessionToken,
  onResent,
}: EmailVerificationBannerProps) {
  const [sending, setSending] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [code, setCode] = useState('');
  const [message, setMessage] = useState('Kayıt sırasında e-postana kod gönderildi. Gelen kutunu kontrol et.');
  const [error, setError] = useState('');
  const [codeSent, setCodeSent] = useState(true);
  const [cooldown, setCooldown] = useState(0);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setInterval(() => {
      setCooldown((prev) => (prev <= 1 ? 0 : prev - 1));
    }, 1000);
    return () => window.clearInterval(timer);
  }, [cooldown]);

  const startCooldown = (seconds: number) => {
    setCooldown(Math.max(0, Math.min(RESEND_COOLDOWN_SEC, seconds)));
  };

  const handleSendCode = async () => {
    if (cooldown > 0) return;
    setSending(true);
    setMessage('');
    setError('');
    try {
      const result = await resendVerificationEmail({ email, uid, sessionToken });
      setMessage(result.message);
      setCodeSent(result.mailSent ?? true);
      startCooldown(result.retryAfter ?? RESEND_COOLDOWN_SEC);
      if (result.user) onResent?.(result.user);
    } catch (err: unknown) {
      const retryAfter =
        err && typeof err === 'object' && 'retryAfter' in err && typeof (err as { retryAfter?: number }).retryAfter === 'number'
          ? (err as { retryAfter: number }).retryAfter
          : undefined;
      if (retryAfter) {
        startCooldown(retryAfter);
        setError(err instanceof Error ? err.message : 'Kod gönderilemedi.');
      } else {
        setError(err instanceof Error ? err.message : 'Kod gönderilemedi.');
      }
    } finally {
      setSending(false);
    }
  };

  const handleVerify = async () => {
    const digits = code.replace(/\D/g, '');
    if (digits.length !== 6) {
      setError('6 haneli kodu gir.');
      return;
    }
    setVerifying(true);
    setMessage('');
    setError('');
    try {
      const result = await verifyEmailCode(digits, { email, uid, sessionToken });
      setMessage(result.message);
      onResent?.(result.user);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Kod doğrulanamadı.');
    } finally {
      setVerifying(false);
    }
  };

  const steps = [
    { done: true, label: 'Hesabın oluşturuldu' },
    { done: codeSent, label: 'E-postandaki kodu gir' },
    { done: false, label: 'Konsola geç ve emanet başlat' },
  ];

  const sendDisabled = sending || cooldown > 0;
  const sendLabel = sending
    ? 'Gönderiliyor...'
    : cooldown > 0
      ? `Tekrar gönder (${cooldown}s)`
      : 'Kod gönder';

  return (
    <motion.div
      id="email-verify-section"
      initial={{ opacity: 0, y: -8 }}
      animate={{ opacity: 1, y: 0 }}
      className="mb-6 rounded-2xl border border-amber-500/35 bg-gradient-to-r from-amber-500/[0.08] via-amber-500/[0.04] to-transparent p-4 sm:p-5 shadow-[0_0_32px_rgba(245,158,11,0.06)]"
      role="status"
      aria-live="polite"
    >
      <div className="flex flex-col lg:flex-row lg:items-start gap-4">
        <div className="flex items-start gap-3 flex-1 min-w-0">
          <div className="h-11 w-11 rounded-xl bg-amber-500/15 border border-amber-500/30 flex items-center justify-center shrink-0">
            <Gift className="h-5 w-5 text-amber-400" />
          </div>
          <div className="min-w-0 text-left">
            <p className="font-mono text-[9px] text-amber-400/90 uppercase tracking-[0.18em] font-bold mb-1">
              Onay bekleniyor
            </p>
            <h3 className="font-display text-base sm:text-lg font-black text-white leading-tight mb-1.5">
              E-postanı doğrula
            </h3>
            <p className="text-xs text-zinc-400 leading-relaxed max-w-2xl">
              <span className="text-zinc-200 font-medium">{email}</span> adresine 6 haneli kod gönderilir.
              Kodu aşağıya yaz — doğrulama sonrası konsola geçebilirsin.
            </p>
          </div>
        </div>

        <button
          type="button"
          onClick={handleSendCode}
          disabled={sendDisabled}
          className="shrink-0 inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl bg-amber-500 hover:bg-amber-400 text-black text-xs font-bold transition disabled:opacity-60 disabled:cursor-not-allowed cursor-pointer min-h-[44px] min-w-[148px]"
        >
          {sending ? (
            <RefreshCw className="h-4 w-4 animate-spin" />
          ) : (
            <Mail className="h-4 w-4" />
          )}
          {sendLabel}
        </button>
      </div>

      <div className="mt-4 rounded-xl border border-amber-500/20 bg-black/20 p-3 sm:p-4 text-left">
        <p className="font-mono text-[9px] text-amber-400/80 uppercase tracking-wider mb-3">
          Doğrulama kodu
        </p>
        <div className="flex flex-col sm:flex-row gap-2">
          <div className="relative flex-1">
            <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-zinc-400" />
            <input
              type="text"
              inputMode="numeric"
              autoComplete="one-time-code"
              maxLength={6}
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
              placeholder="6 haneli kod"
              className="w-full pl-10 pr-4 py-3 rounded-xl bg-zinc-950 border border-zinc-800 text-white text-center text-lg font-mono tracking-[0.35em] placeholder:tracking-normal placeholder:text-sm placeholder:text-zinc-600 focus:border-amber-500/50 focus:outline-none"
            />
          </div>
          <button
            type="button"
            onClick={handleVerify}
            disabled={verifying || code.length < 6}
            className="shrink-0 inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition disabled:opacity-50 cursor-pointer min-h-[48px]"
          >
            {verifying ? <RefreshCw className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
            {verifying ? 'Kontrol...' : 'Doğrula'}
          </button>
        </div>
      </div>

      <div className="mt-4 pt-4 border-t border-amber-500/15 grid grid-cols-1 sm:grid-cols-3 gap-3">
        {steps.map((step, i) => (
          <div
            key={step.label}
            className={`flex items-center gap-2 rounded-xl px-3 py-2.5 text-left ${
              step.done
                ? 'bg-emerald-500/10 border border-emerald-500/20'
                : i === 1
                  ? 'bg-amber-500/10 border border-amber-500/25'
                  : 'bg-white/[0.02] border border-white/[0.06]'
            }`}
          >
            {step.done ? (
              <CheckCircle2 className="h-4 w-4 text-emerald-400 shrink-0" />
            ) : i === 1 ? (
              <KeyRound className="h-4 w-4 text-amber-400 shrink-0" />
            ) : (
              <Sparkles className="h-4 w-4 text-zinc-400 shrink-0" />
            )}
            <span
              className={`text-[11px] font-medium leading-tight ${
                step.done ? 'text-emerald-300' : i === 1 ? 'text-amber-200' : 'text-zinc-400'
              }`}
            >
              {i + 1}. {step.label}
            </span>
          </div>
        ))}
      </div>

      {(message || error) && (
        <p className={`mt-3 text-xs font-medium text-left ${error ? 'text-red-400' : 'text-emerald-400'}`}>
          {error || message}
        </p>
      )}

      <p className="mt-3 text-[10px] text-zinc-400 text-left flex items-center gap-1">
        <ChevronRight className="h-3 w-3" />
        Kod 15 dakika geçerlidir. Gelmezse spam / gereksiz klasörünü kontrol et (gönderen: noreply@zinesh.com). Yeni kod için 1 dakika bekle.
      </p>
    </motion.div>
  );
}
