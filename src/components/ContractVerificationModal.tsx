import React from 'react';
import { AlertTriangle, Mail, Phone, ShieldCheck, X } from 'lucide-react';
import {
  VERIFICATION_TIER_HINTS,
  VERIFICATION_TIER_LABELS,
  type VerificationTier,
} from '../lib/contractVerification';

interface ContractVerificationModalProps {
  open: boolean;
  missing: VerificationTier[];
  onClose: () => void;
  onGoToVerification: (tier: VerificationTier) => void;
}

function TierIcon({ tier }: { tier: VerificationTier }) {
  const cls = 'h-4 w-4 shrink-0';
  if (tier === 'email') return <Mail className={`${cls} text-amber-300`} aria-hidden />;
  if (tier === 'phone') return <Phone className={`${cls} text-sky-300`} aria-hidden />;
  return <ShieldCheck className={`${cls} text-emerald-300`} aria-hidden />;
}

export default function ContractVerificationModal({
  open,
  missing,
  onClose,
  onGoToVerification,
}: ContractVerificationModalProps) {
  if (!open || missing.length === 0) return null;

  const primary = missing[0];

  return (
    <div
      className="fixed inset-0 z-[80] flex items-end sm:items-center justify-center p-4 sm:p-6"
      role="presentation"
    >
      <button
        type="button"
        className="absolute inset-0 bg-black/70 backdrop-blur-[2px] cursor-pointer"
        aria-label="Kapat"
        onClick={onClose}
      />
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="contract-verification-title"
        className="relative w-full max-w-md rounded-2xl border border-amber-500/30 bg-[#0a0a10] shadow-2xl shadow-black/60 overflow-hidden"
      >
        <div className="px-5 py-4 border-b border-zinc-900 flex items-start justify-between gap-3">
          <div className="flex items-start gap-3 min-w-0">
            <div className="h-10 w-10 rounded-xl bg-amber-500/15 border border-amber-500/25 flex items-center justify-center shrink-0">
              <AlertTriangle className="h-5 w-5 text-amber-300" aria-hidden />
            </div>
            <div className="min-w-0 text-left">
              <p className="font-mono text-[9px] text-amber-400/90 uppercase tracking-[0.18em] font-bold mb-1">
                Doğrulama gerekli
              </p>
              <h2 id="contract-verification-title" className="text-base font-bold text-white leading-snug">
                Yeni iş başlatmak için doğrulamayı tamamla
              </h2>
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            className="shrink-0 p-2 rounded-lg text-zinc-500 hover:text-zinc-200 hover:bg-zinc-800 cursor-pointer"
            aria-label="Kapat"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        <div className="px-5 py-4 space-y-3 text-left">
          <p className="text-sm text-zinc-400 leading-relaxed">
            Güvenli emanet için e-posta ve kimlik doğrulaması zorunludur. Eksik adımları profilinden
            tamamlayabilirsin.
          </p>
          <ul className="space-y-2">
            {missing.map((tier) => (
              <li
                key={tier}
                className="rounded-xl border border-zinc-800 bg-zinc-950/60 px-3 py-3 flex items-start gap-3"
              >
                <TierIcon tier={tier} />
                <div className="min-w-0">
                  <p className="text-sm font-semibold text-zinc-100">{VERIFICATION_TIER_LABELS[tier]}</p>
                  <p className="text-[11px] text-zinc-500 mt-1 leading-relaxed">{VERIFICATION_TIER_HINTS[tier]}</p>
                </div>
              </li>
            ))}
          </ul>
        </div>

        <div className="px-5 py-4 border-t border-zinc-900 flex flex-col sm:flex-row gap-2">
          <button
            type="button"
            onClick={() => onGoToVerification(primary)}
            className="flex-1 min-h-[44px] rounded-xl bg-white text-black text-sm font-bold cursor-pointer"
          >
            {VERIFICATION_TIER_LABELS[primary]}na git
          </button>
          <button
            type="button"
            onClick={onClose}
            className="flex-1 min-h-[44px] rounded-xl border border-zinc-800 text-zinc-300 text-sm font-semibold cursor-pointer hover:bg-zinc-900"
          >
            Kapat
          </button>
        </div>
      </div>
    </div>
  );
}
