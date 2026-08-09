import React, { useState } from 'react';
import { Pencil, User } from 'lucide-react';
import { updateProfileName } from '../lib/auth';
import type { UserProfile } from '../lib/userProfile';

interface ProfileNameSectionProps {
  name: string;
  kycStatus?: string;
  onUpdated?: (user: UserProfile) => void;
}

export default function ProfileNameSection({ name, kycStatus, onUpdated }: ProfileNameSectionProps) {
  const [editing, setEditing] = useState(false);
  const [value, setValue] = useState(name);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [success, setSuccess] = useState('');

  const locked = kycStatus === 'approved';

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault();
    setError('');
    setSuccess('');
    const trimmed = value.trim();
    if (trimmed.length < 2) {
      setError('Ad soyad en az 2 karakter olmalı.');
      return;
    }
    if (trimmed === name.trim()) {
      setEditing(false);
      return;
    }
    setLoading(true);
    try {
      const user = await updateProfileName(trimmed);
      setSuccess('Ad soyad güncellendi.');
      setEditing(false);
      onUpdated?.(user);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Kaydedilemedi.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <section className="rounded-2xl border border-zinc-800 bg-[rgba(255,255,255,0.02)] p-4 sm:p-5 text-left">
      <h3 className="text-[10px] font-mono font-bold text-zinc-400 uppercase tracking-wider mb-3 flex items-center gap-2">
        <User className="h-3.5 w-3.5" />
        Ad soyad
      </h3>
      {locked ? (
        <p className="text-xs text-zinc-400 leading-relaxed">
          Kimlik doğrulaması onaylı hesaplarda ad değişikliği destek üzerinden yapılır.
          <span className="block mt-2 text-sm text-zinc-100 font-medium">{name}</span>
        </p>
      ) : editing ? (
        <form onSubmit={handleSave} className="space-y-3 max-w-md">
          <input
            type="text"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            maxLength={80}
            required
            className="w-full bg-zinc-950 border border-zinc-800 rounded-xl px-3 py-2.5 text-sm text-zinc-100 focus:outline-none focus:border-purple-500/40"
            autoComplete="name"
          />
          {error && (
            <p className="text-xs text-red-400 bg-red-950/30 border border-red-500/20 rounded-lg px-3 py-2">{error}</p>
          )}
          {success && (
            <p className="text-xs text-emerald-300 bg-emerald-950/20 border border-emerald-500/20 rounded-lg px-3 py-2">{success}</p>
          )}
          <div className="flex flex-wrap gap-2">
            <button
              type="submit"
              disabled={loading}
              className="px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-500 text-white text-sm font-semibold cursor-pointer disabled:opacity-60"
            >
              {loading ? 'Kaydediliyor...' : 'Kaydet'}
            </button>
            <button
              type="button"
              onClick={() => {
                setValue(name);
                setEditing(false);
                setError('');
                setSuccess('');
              }}
              className="px-4 py-2 rounded-xl border border-zinc-700 text-zinc-300 text-sm font-semibold hover:bg-zinc-900 cursor-pointer"
            >
              İptal
            </button>
          </div>
        </form>
      ) : (
        <div className="flex items-center justify-between gap-3">
          <p className="text-sm text-zinc-100 font-medium">{name}</p>
          <button
            type="button"
            onClick={() => {
              setValue(name);
              setEditing(true);
              setError('');
              setSuccess('');
            }}
            className="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-zinc-700 text-[11px] font-semibold text-zinc-300 hover:bg-zinc-900 cursor-pointer"
          >
            <Pencil className="w-3 h-3" />
            Düzenle
          </button>
        </div>
      )}
    </section>
  );
}
