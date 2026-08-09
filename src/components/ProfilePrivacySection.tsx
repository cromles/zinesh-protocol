import React, { useState } from 'react';
import { Eye, EyeOff } from 'lucide-react';
import { setJobHistoryVisibility } from '../lib/trustProfileApi';

interface ProfilePrivacySectionProps {
  jobHistoryPublic?: boolean;
  onUpdated?: (isPublic: boolean) => void;
}

export default function ProfilePrivacySection({
  jobHistoryPublic = true,
  onUpdated,
}: ProfilePrivacySectionProps) {
  const [isPublic, setIsPublic] = useState(jobHistoryPublic);
  const [loading, setLoading] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState('');

  const handleToggle = async () => {
    const next = !isPublic;
    setLoading(true);
    setMessage('');
    setError('');
    try {
      const result = await setJobHistoryVisibility(next);
      setIsPublic(result.jobHistoryPublic);
      setMessage(result.message);
      onUpdated?.(result.jobHistoryPublic);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Tercih kaydedilemedi.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <section className="rounded-2xl border border-zinc-800 bg-[rgba(255,255,255,0.02)] p-4 sm:p-5 text-left">
      <h3 className="text-[10px] font-mono font-bold text-zinc-400 uppercase tracking-wider mb-3 flex items-center gap-2">
        {isPublic ? <Eye className="h-3.5 w-3.5" /> : <EyeOff className="h-3.5 w-3.5" />}
        Gizlilik
      </h3>
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div className="min-w-0">
          <p className="text-sm text-zinc-200 font-medium">İş geçmişi görünürlüğü</p>
          <p className="text-xs text-zinc-500 mt-1 leading-relaxed">
            {isPublic
              ? 'Tamamlanan emanet işlerin profilinde listelenir. Güven puanın her zaman görünür.'
              : 'İş geçmişin gizli. Yalnızca güven puanın görünür.'}
          </p>
        </div>
        <button
          type="button"
          role="switch"
          aria-checked={isPublic}
          disabled={loading}
          onClick={() => void handleToggle()}
          className={`shrink-0 relative w-12 h-7 rounded-full border transition cursor-pointer disabled:opacity-60 ${
            isPublic ? 'bg-emerald-600/80 border-emerald-500/50' : 'bg-zinc-800 border-zinc-700'
          }`}
        >
          <span
            className={`absolute top-0.5 left-0.5 w-6 h-6 rounded-full bg-white shadow transition-transform ${
              isPublic ? 'translate-x-5' : 'translate-x-0'
            }`}
          />
        </button>
      </div>
      {error && <p className="mt-3 text-xs text-red-400">{error}</p>}
      {message && !error && <p className="mt-3 text-xs text-emerald-400">{message}</p>}
    </section>
  );
}
