import React, { useState } from 'react';
import { ArrowLeft, Lock, Mail, ShieldCheck } from 'lucide-react';
import {
  resetPasswordWithCode,
  sendForgotPasswordCode,
  verifyForgotPasswordCode,
} from '../lib/auth';

type Step = 'email' | 'code' | 'password' | 'done';

interface ForgotPasswordPanelProps {
  initialEmail?: string;
  onBackToLogin: () => void;
}

export default function ForgotPasswordPanel({ initialEmail = '', onBackToLogin }: ForgotPasswordPanelProps) {
  const [step, setStep] = useState<Step>('email');
  const [email, setEmail] = useState(initialEmail);
  const [code, setCode] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState('');
  const [info, setInfo] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSendCode = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!email.trim()) {
      setError('E-posta adresi gerekli.');
      return;
    }
    setError('');
    setInfo('');
    setLoading(true);
    try {
      const result = await sendForgotPasswordCode(email);
      setInfo(result.message);
      setStep('code');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Kod gönderilemedi.');
    } finally {
      setLoading(false);
    }
  };

  const handleVerifyCode = async (e: React.FormEvent) => {
    e.preventDefault();
    if (code.replace(/\D/g, '').length < 6) {
      setError('6 haneli kodu gir.');
      return;
    }
    setError('');
    setLoading(true);
    try {
      const result = await verifyForgotPasswordCode(email, code);
      setInfo(result.message);
      setStep('password');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Kod doğrulanamadı.');
    } finally {
      setLoading(false);
    }
  };

  const handleResetPassword = async (e: React.FormEvent) => {
    e.preventDefault();
    if (newPassword.length < 8) {
      setError('Şifre en az 8 karakter olmalı.');
      return;
    }
    if (newPassword !== confirmPassword) {
      setError('Şifreler eşleşmiyor.');
      return;
    }
    setError('');
    setLoading(true);
    try {
      const result = await resetPasswordWithCode(email, code, newPassword, confirmPassword);
      setInfo(result.message);
      setStep('done');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Şifre güncellenemedi.');
    } finally {
      setLoading(false);
    }
  };

  const inputClass =
    'w-full rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 text-sm text-slate-100 placeholder-slate-500 transition-colors focus:border-emerald-500/40 focus:outline-none';

  return (
    <div className="space-y-5">
      <button
        type="button"
        onClick={onBackToLogin}
        className="text-xs text-zinc-400 hover:text-white flex items-center gap-1 transition cursor-pointer"
      >
        <ArrowLeft className="h-3 w-3" /> Giriş ekranına dön
      </button>

      <div className="text-center space-y-1">
        <div className="inline-flex h-10 w-10 items-center justify-center rounded-full bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 mb-2">
          <ShieldCheck className="h-5 w-5" />
        </div>
        <h4 className="font-display font-bold text-white text-lg">Şifremi Unuttum</h4>
        <p className="text-xs text-zinc-400">
          {step === 'email' && 'E-posta adresine 6 haneli kod göndereceğiz.'}
          {step === 'code' && 'E-postandaki kodu gir.'}
          {step === 'password' && 'Yeni şifreni belirle.'}
          {step === 'done' && 'İşlem tamamlandı.'}
        </p>
      </div>

      {error && (
        <p className="text-xs text-red-400 bg-red-950/30 border border-red-500/20 rounded-lg px-3 py-2">{error}</p>
      )}
      {info && !error && (
        <p className="text-xs text-emerald-300 bg-emerald-950/20 border border-emerald-500/20 rounded-lg px-3 py-2">{info}</p>
      )}

      {step === 'email' && (
        <form onSubmit={handleSendCode} className="space-y-4">
          <div>
            <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5 flex items-center gap-1">
              <Mail className="h-3 w-3" /> E-posta
            </label>
            <input
              type="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="ornek@mail.com"
              className={inputClass}
            />
          </div>
          <button
            type="submit"
            disabled={loading}
            className="w-full py-3.5 rounded-xl bg-emerald-600 text-white font-semibold text-sm hover:bg-emerald-500 transition cursor-pointer disabled:opacity-60"
          >
            {loading ? 'Gönderiliyor...' : 'Kod Gönder'}
          </button>
        </form>
      )}

      {step === 'code' && (
        <form onSubmit={handleVerifyCode} className="space-y-4">
          <div>
            <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5">
              6 haneli kod
            </label>
            <input
              type="text"
              inputMode="numeric"
              pattern="[0-9]{6}"
              maxLength={6}
              required
              value={code}
              onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))}
              placeholder="123456"
              className={`${inputClass} tracking-widest font-mono text-center`}
            />
            <p className="text-[10px] text-zinc-400 mt-2">Kod 15 dakika geçerlidir. En fazla 5 yanlış deneme.</p>
          </div>
          <button
            type="submit"
            disabled={loading}
            className="w-full py-3.5 rounded-xl bg-emerald-600 text-white font-semibold text-sm hover:bg-emerald-500 transition cursor-pointer disabled:opacity-60"
          >
            {loading ? 'Doğrulanıyor...' : 'Kodu Doğrula'}
          </button>
          <button
            type="button"
            disabled={loading}
            onClick={() => {
              setStep('email');
              setCode('');
              setError('');
            }}
            className="w-full text-xs text-zinc-400 hover:text-white cursor-pointer"
          >
            Yeni kod gönder
          </button>
        </form>
      )}

      {step === 'password' && (
        <form onSubmit={handleResetPassword} className="space-y-4">
          <div>
            <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5 flex items-center gap-1">
              <Lock className="h-3 w-3" /> Yeni şifre
            </label>
            <input
              type="password"
              required
              minLength={8}
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              className={inputClass}
            />
          </div>
          <div>
            <label className="font-mono text-[10px] text-zinc-400 uppercase tracking-wider block mb-1.5">
              Yeni şifre tekrar
            </label>
            <input
              type="password"
              required
              minLength={8}
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              className={inputClass}
            />
          </div>
          <button
            type="submit"
            disabled={loading}
            className="w-full py-3.5 rounded-xl bg-emerald-600 text-white font-semibold text-sm hover:bg-emerald-500 transition cursor-pointer disabled:opacity-60"
          >
            {loading ? 'Kaydediliyor...' : 'Şifreyi Sıfırla'}
          </button>
        </form>
      )}

      {step === 'done' && (
        <div className="space-y-4 text-center">
          <p className="text-sm text-zinc-300">Tüm oturumların kapatıldı. Yeni şifrenle giriş yapabilirsin.</p>
          <button
            type="button"
            onClick={onBackToLogin}
            className="w-full py-3.5 rounded-xl bg-emerald-600 text-white font-semibold text-sm hover:bg-emerald-500 transition cursor-pointer"
          >
            Giriş Yap
          </button>
        </div>
      )}
    </div>
  );
}
