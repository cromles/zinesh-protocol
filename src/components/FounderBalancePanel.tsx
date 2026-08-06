import React, { useState } from 'react';
import { PlusCircle } from 'lucide-react';
import { founderSetUserBalance } from '../lib/founderPlatformApi';
import { getSessionToken } from '../lib/auth';

type FounderBalancePanelProps = {
  sessionToken?: string;
  onDone?: () => void;
};

export default function FounderBalancePanel({ sessionToken, onDone }: FounderBalancePanelProps) {
  const [lookup, setLookup] = useState('');
  const [amount, setAmount] = useState('');
  const [mode, setMode] = useState<'add' | 'set'>('add');
  const [note, setNote] = useState('');
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    const parsed = Number(String(amount).replace(',', '.'));
    if (!lookup.trim()) {
      setError('Kullanıcı (e-posta / ZN-SH-… / uid) girin.');
      return;
    }
    if (!Number.isFinite(parsed) || (mode === 'add' && parsed === 0)) {
      setError('Geçerli bir tutar girin.');
      return;
    }
    setLoading(true);
    setError(null);
    setMessage(null);
    try {
      const result = await founderSetUserBalance({
        lookup,
        amount: parsed,
        mode,
        note,
        sessionToken: sessionToken ?? getSessionToken(),
      });
      setMessage(result.message);
      setAmount('');
      setNote('');
      onDone?.();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Bakiye güncellenemedi.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="rounded-2xl border border-emerald-500/25 bg-gradient-to-br from-emerald-500/[0.06] to-sky-500/[0.03] p-4 sm:p-5 text-left">
      <div className="mb-4">
        <p className="font-mono text-[10px] text-emerald-400 uppercase tracking-widest font-bold mb-0.5 flex items-center gap-1.5">
          <PlusCircle className="h-3.5 w-3.5" /> Bakiye
        </p>
        <h3 className="font-display text-base font-bold text-white">Kullanıcıya bakiye ekle</h3>
        <p className="text-[11px] text-zinc-500 mt-1">
          Havale geldiğinde e-posta veya üye numarasıyla bakiyeyi buradan işleyin. Ayrı admin hesabı yok — bu kurucu paneli.
        </p>
        <a
          href="/api/admin.php"
          target="_blank"
          rel="noreferrer"
          className="inline-block mt-2 text-[11px] text-emerald-400/90 hover:text-emerald-300 underline underline-offset-2"
        >
          Gelişmiş yönetim (çekim / havale onay) →
        </a>
      </div>

      <form onSubmit={submit} className="space-y-3">
        <label className="block">
          <span className="text-[10px] font-mono uppercase tracking-wider text-zinc-500">Kullanıcı</span>
          <input
            type="text"
            value={lookup}
            onChange={(e) => setLookup(e.target.value)}
            placeholder="email / ZN-SH-… / uid"
            className="mt-1 w-full rounded-xl border border-zinc-700 bg-[#09090e] px-3 py-2.5 text-sm text-white placeholder:text-zinc-600 outline-none focus:border-emerald-500/50"
            required
          />
        </label>

        <div className="grid grid-cols-2 gap-3">
          <label className="block">
            <span className="text-[10px] font-mono uppercase tracking-wider text-zinc-500">Tutar (TL)</span>
            <input
              type="text"
              inputMode="decimal"
              value={amount}
              onChange={(e) => setAmount(e.target.value)}
              placeholder="1000"
              className="mt-1 w-full rounded-xl border border-zinc-700 bg-[#09090e] px-3 py-2.5 text-sm text-white placeholder:text-zinc-600 outline-none focus:border-emerald-500/50"
              required
            />
          </label>
          <label className="block">
            <span className="text-[10px] font-mono uppercase tracking-wider text-zinc-500">İşlem</span>
            <select
              value={mode}
              onChange={(e) => setMode(e.target.value as 'add' | 'set')}
              className="mt-1 w-full rounded-xl border border-zinc-700 bg-[#09090e] px-3 py-2.5 text-sm text-white outline-none focus:border-emerald-500/50"
            >
              <option value="add">Ekle (+)</option>
              <option value="set">Mutlak ayarla (=)</option>
            </select>
          </label>
        </div>

        <label className="block">
          <span className="text-[10px] font-mono uppercase tracking-wider text-zinc-500">Not (opsiyonel)</span>
          <input
            type="text"
            value={note}
            onChange={(e) => setNote(e.target.value)}
            placeholder="Havale dekont / açıklama"
            className="mt-1 w-full rounded-xl border border-zinc-700 bg-[#09090e] px-3 py-2.5 text-sm text-white placeholder:text-zinc-600 outline-none focus:border-emerald-500/50"
          />
        </label>

        {error && <p className="text-sm text-rose-300">{error}</p>}
        {message && <p className="text-sm text-emerald-300">{message}</p>}

        <button
          type="submit"
          disabled={loading}
          className="w-full min-h-[48px] rounded-2xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-sm disabled:opacity-50 cursor-pointer"
        >
          {loading ? 'İşleniyor…' : 'Bakiyeyi güncelle'}
        </button>
      </form>
    </div>
  );
}
