import React, { useEffect, useRef, useState } from 'react';
import { CheckCircle2, KeyRound, Phone, RefreshCw } from 'lucide-react';
import { confirmPhoneWithFirebase } from '../lib/auth';
import { confirmFirebasePhoneOtp, startFirebasePhoneOtp, type PhoneOtpSession } from '../lib/firebasePhoneAuth';
import type { UserProfile } from '../lib/userProfile';

interface ProfilePhoneSectionProps {
  emailVerified?: boolean;
  phoneVerified?: boolean;
  phoneMasked?: string;
  onUpdated?: (user: UserProfile) => void;
}

const RECAPTCHA_ID = 'zinesh-phone-recaptcha';
const RESEND_COOLDOWN_SEC = 60;

export default function ProfilePhoneSection({
  emailVerified = false,
  phoneVerified = false,
  phoneMasked,
  onUpdated,
}: ProfilePhoneSectionProps) {
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [sending, setSending] = useState(false);
  const [verifying, setVerifying] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');
  const [codeSent, setCodeSent] = useState(false);
  const [cooldown, setCooldown] = useState(0);
  const otpSessionRef = useRef<PhoneOtpSession | null>(null);

  useEffect(() => {
    if (cooldown <= 0) return;
    const timer = window.setInterval(() => {
      setCooldown((prev) => (prev <= 1 ? 0 : prev - 1));
    }, 1000);
    return () => window.clearInterval(timer);
  }, [cooldown]);

  useEffect(() => {
    return () => {
      otpSessionRef.current?.cleanup();
      otpSessionRef.current = null;
    };
  }, []);

  const handleSendCode = async () => {
    if (cooldown > 0) return;
    setSending(true);
    setMessage('');
    setError('');
    otpSessionRef.current?.cleanup();
    otpSessionRef.current = null;
    try {
      const session = await startFirebasePhoneOtp(phone, RECAPTCHA_ID);
      otpSessionRef.current = session;
      setCodeSent(true);
      setCooldown(RESEND_COOLDOWN_SEC);
      setMessage(`SMS kodu ${session.e164} numarasına gönderildi.`);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'SMS gönderilemedi.');
      setCodeSent(false);
    } finally {
      setSending(false);
    }
  };

  const handleVerify = async () => {
    const session = otpSessionRef.current;
    if (!session) {
      setError('Önce SMS kodu gönder.');
      return;
    }
    setVerifying(true);
    setMessage('');
    setError('');
    try {
      const idToken = await confirmFirebasePhoneOtp(session, code);
      otpSessionRef.current = null;
      const result = await confirmPhoneWithFirebase(idToken);
      setMessage(result.message);
      onUpdated?.(result.user);
      setCode('');
      setCodeSent(false);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : 'Kod doğrulanamadı.');
    } finally {
      setVerifying(false);
    }
  };

  return (
    <section
      id="phone-verify-section"
      className="rounded-2xl border border-zinc-800 bg-[rgba(255,255,255,0.02)] p-4 sm:p-5"
    >
      <h3 className="text-[10px] font-mono font-bold text-zinc-400 uppercase tracking-wider mb-3 flex items-center gap-2">
        <Phone className="h-3.5 w-3.5" aria-hidden />
        Telefon (SMS)
      </h3>

      <div id={RECAPTCHA_ID} className="sr-only" aria-hidden />

      {phoneVerified ? (
        <div className="flex flex-wrap items-center gap-2">
          <p className="text-sm text-zinc-100">
            {phoneMasked ? `Doğrulanmış numara: ${phoneMasked}` : 'Telefon doğrulandı.'}
          </p>
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold border text-emerald-300 bg-emerald-500/10 border-emerald-500/25">
            <CheckCircle2 className="w-3 h-3" aria-hidden />
            Doğrulandı
          </span>
        </div>
      ) : (
        <div className="space-y-3 text-left">
          <p className="text-[11px] text-zinc-500 leading-relaxed">
            Numaranı gir; Firebase üzerinden SMS ile 6 haneli kod gelir. Kodu girdikten sonra hesabın
            telefon doğrulaması tamamlanır.
          </p>
          {!emailVerified && (
            <p className="text-xs text-amber-300/90 bg-amber-500/10 border border-amber-500/20 rounded-lg px-3 py-2">
              Telefon doğrulaması için önce e-postanı doğrulaman gerekir.
            </p>
          )}
          <div className="flex flex-col sm:flex-row gap-2">
            <input
              type="tel"
              inputMode="tel"
              autoComplete="tel"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
              placeholder="5XX XXX XX XX"
              disabled={!emailVerified}
              className="flex-1 min-h-[44px] rounded-xl bg-zinc-950 border border-zinc-800 px-4 text-sm text-white placeholder:text-zinc-600 focus:outline-none focus:border-sky-500/40 disabled:opacity-50"
            />
            <button
              type="button"
              onClick={handleSendCode}
              disabled={!emailVerified || sending || cooldown > 0 || phone.replace(/\D/g, '').length < 10}
              className="shrink-0 min-h-[44px] px-4 rounded-xl bg-sky-600 hover:bg-sky-500 text-white text-xs font-bold disabled:opacity-50 cursor-pointer inline-flex items-center justify-center gap-2"
            >
              {sending ? <RefreshCw className="h-4 w-4 animate-spin" /> : <Phone className="h-4 w-4" />}
              {sending ? 'Gönderiliyor…' : cooldown > 0 ? `Tekrar (${cooldown}s)` : 'SMS kodu gönder'}
            </button>
          </div>

          {codeSent && (
            <div className="rounded-xl border border-zinc-800 bg-zinc-950/60 p-3 space-y-2">
              <p className="text-[10px] font-mono uppercase tracking-wider text-zinc-500">SMS kodu</p>
              <div className="flex flex-col sm:flex-row gap-2">
                <div className="relative flex-1">
                  <KeyRound className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-zinc-500" />
                  <input
                    type="text"
                    inputMode="numeric"
                    autoComplete="one-time-code"
                    maxLength={6}
                    value={code}
                    onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
                    placeholder="6 haneli kod"
                    className="w-full pl-10 pr-4 py-3 rounded-xl bg-zinc-950 border border-zinc-800 text-white text-center text-lg font-mono tracking-[0.35em] placeholder:tracking-normal placeholder:text-sm placeholder:text-zinc-600 focus:border-sky-500/40 focus:outline-none"
                  />
                </div>
                <button
                  type="button"
                  onClick={handleVerify}
                  disabled={verifying || code.length < 6}
                  className="shrink-0 min-h-[48px] px-5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold disabled:opacity-50 cursor-pointer inline-flex items-center justify-center gap-2"
                >
                  {verifying ? <RefreshCw className="h-4 w-4 animate-spin" /> : <CheckCircle2 className="h-4 w-4" />}
                  {verifying ? 'Kontrol…' : 'Doğrula'}
                </button>
              </div>
            </div>
          )}

          {(message || error) && (
            <p className={`text-xs font-medium ${error ? 'text-red-400' : 'text-emerald-400'}`}>{error || message}</p>
          )}
        </div>
      )}
    </section>
  );
}
