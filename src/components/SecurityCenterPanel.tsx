import React, { useState } from 'react';
import { Lock, Shield } from 'lucide-react';
import { changePassword } from '../lib/auth';
import { OAUTH_PROVIDERS } from '../lib/oauthProviders';

interface SecurityCenterPanelProps {
  googleLinked?: boolean;
  totpEnabled?: boolean;
  onPasswordChanged?: () => void;
}

export default function SecurityCenterPanel({
  googleLinked = false,
  totpEnabled = false,
  onPasswordChanged,
}: SecurityCenterPanelProps) {
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');
  const [loading, setLoading] = useState(false);

  const googleProvider = OAUTH_PROVIDERS.google;

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setSuccess('');
    if (newPassword.length < 8) {
      setError('Yeni şifre en az 8 karakter olmalı.');
      return;
    }
    if (newPassword !== confirmPassword) {
      setError('Yeni şifreler eşleşmiyor.');
      return;
    }
    setLoading(true);
    try {
      const result = await changePassword(currentPassword, newPassword, confirmPassword);
      setSuccess(result.message);
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
      if (result.requiresLogin) {
        onPasswordChanged?.();
      }
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Şifre değiştirilemedi.');
    } finally {
      setLoading(false);
    }
  };

  const inputClass =
    'w-full bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2.5 text-sm text-zinc-100 placeholder-zinc-600 focus:outline-none focus:border-purple-500/40';

  return (
    <div className="space-y-6">
      <section className="rounded-2xl border border-zinc-900 bg-[#09090e] p-5">
        <h4 className="text-[10px] font-mono font-bold text-zinc-400 uppercase tracking-wider mb-4 flex items-center gap-2">
          <Lock className="h-3.5 w-3.5" /> Şifre değiştir
        </h4>
        <p className="text-xs text-zinc-400 mb-4">
          Mevcut şifreni bilmeden panelden şifre değiştiremezsin. Başarılı değişiklikten sonra tüm oturumlar kapanır.
        </p>
        {error && (
          <p className="text-xs text-red-400 bg-red-950/30 border border-red-500/20 rounded-lg px-3 py-2 mb-3">{error}</p>
        )}
        {success && (
          <p className="text-xs text-emerald-300 bg-emerald-950/20 border border-emerald-500/20 rounded-lg px-3 py-2 mb-3">{success}</p>
        )}
        <form onSubmit={handleSubmit} autoComplete="off" className="space-y-3 max-w-md">
          <input
            type="text"
            name="zinesh-decoy-username"
            autoComplete="username"
            tabIndex={-1}
            aria-hidden
            className="absolute opacity-0 pointer-events-none h-0 w-0"
            defaultValue=""
          />
          <div>
            <label className="text-[10px] font-mono text-zinc-400 uppercase block mb-1">Mevcut şifre</label>
            <input
              type="password"
              name="zinesh-current-password"
              required
              value={currentPassword}
              onChange={(e) => setCurrentPassword(e.target.value)}
              className={inputClass}
              autoComplete="off"
              data-lpignore="true"
              data-1p-ignore
              readOnly
              onFocus={(e) => e.currentTarget.removeAttribute('readonly')}
            />
          </div>
          <div>
            <label className="text-[10px] font-mono text-zinc-400 uppercase block mb-1">Yeni şifre</label>
            <input
              type="password"
              name="zinesh-new-password"
              required
              minLength={8}
              value={newPassword}
              onChange={(e) => setNewPassword(e.target.value)}
              className={inputClass}
              autoComplete="new-password"
              data-lpignore="true"
              data-1p-ignore
            />
          </div>
          <div>
            <label className="text-[10px] font-mono text-zinc-400 uppercase block mb-1">Yeni şifre tekrar</label>
            <input
              type="password"
              name="zinesh-new-password-confirm"
              required
              minLength={8}
              value={confirmPassword}
              onChange={(e) => setConfirmPassword(e.target.value)}
              className={inputClass}
              autoComplete="new-password"
              data-lpignore="true"
              data-1p-ignore
            />
          </div>
          <button
            type="submit"
            disabled={loading}
            className="px-4 py-2.5 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold transition cursor-pointer disabled:opacity-60"
          >
            {loading ? 'Kaydediliyor...' : 'Şifreyi Değiştir'}
          </button>
        </form>
      </section>

      <section className="rounded-2xl border border-zinc-900 bg-[#09090e] p-5">
        <h4 className="text-[10px] font-mono font-bold text-zinc-400 uppercase tracking-wider mb-3 flex items-center gap-2">
          <Shield className="h-3.5 w-3.5" /> İki adımlı doğrulama (2FA)
        </h4>
        <p className="text-sm text-zinc-300">
          Durum:{' '}
          <span className={totpEnabled ? 'text-emerald-300 font-semibold' : 'text-zinc-400'}>
            {totpEnabled ? 'Aktif' : 'Kapalı'}
          </span>
        </p>
        {totpEnabled && (
          <p className="text-[11px] text-zinc-500 mt-2 leading-relaxed">
            Authenticator sıfırlamak için çıkış yapıp giriş ekranındaki &quot;Authenticator sıfırla&quot; bağlantısını kullan.
          </p>
        )}
      </section>

      <section className="rounded-2xl border border-zinc-900 bg-[#09090e] p-5">
        <h4 className="text-[10px] font-mono font-bold text-zinc-400 uppercase tracking-wider mb-3 flex items-center gap-2">
          <Shield className="h-3.5 w-3.5" /> Bağlı hesaplar
        </h4>
        <div className="flex items-center justify-between gap-3 rounded-xl border border-zinc-800 bg-zinc-950/60 px-4 py-3">
          <div>
            <p className="text-sm text-zinc-300">{googleProvider.label}</p>
            <p className="text-[10px] text-zinc-400 font-mono">
              {googleLinked
                ? 'Hesabın Google ile bağlı'
                : 'Giriş ekranından Google ile bağlanabilirsin'}
            </p>
          </div>
          <span
            className={`text-[10px] font-mono px-2 py-1 rounded-full border ${
              googleLinked
                ? 'bg-emerald-500/10 text-emerald-300 border-emerald-500/30'
                : 'bg-zinc-800 text-zinc-400 border-zinc-700'
            }`}
          >
            {googleLinked ? 'Bağlı' : googleProvider.enabled ? 'Bağlı değil' : 'Kapalı'}
          </span>
        </div>
      </section>
    </div>
  );
}
