import React, { useState } from 'react';
import { ChevronDown, Copy } from 'lucide-react';

export default function TimelineNodeShell({
  layerLabel,
  label,
  summary,
  whyText,
  hoverTitle,
  borderClass,
  labelClass,
}: {
  layerLabel: string;
  label: string;
  summary?: string;
  whyText: string;
  hoverTitle?: string;
  borderClass: string;
  labelClass: string;
}) {
  const [open, setOpen] = useState(false);
  const [copied, setCopied] = useState(false);
  const copyText = [layerLabel, label, summary, whyText].filter(Boolean).join('\n');

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(copyText);
      setCopied(true);
      window.setTimeout(() => setCopied(false), 1500);
    } catch {
      setCopied(false);
    }
  };

  return (
    <div
      className={`group rounded-lg border px-3 py-2 ${borderClass}`}
      title={hoverTitle || copyText}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 flex-1">
          <p className={`text-[10px] uppercase tracking-wider font-bold ${labelClass}`}>
            {layerLabel}
          </p>
          <p className="text-sm font-mono text-zinc-100 break-all select-text">{label}</p>
          {summary ? (
            <p className="text-xs text-zinc-400 mt-0.5 leading-relaxed select-text">{summary}</p>
          ) : null}
        </div>
        <button
          type="button"
          onClick={() => void handleCopy()}
          className="shrink-0 rounded p-1 text-zinc-500 opacity-0 group-hover:opacity-100 hover:text-zinc-300 hover:bg-zinc-800/80 cursor-pointer transition"
          title={copied ? 'Kopyalandı' : 'Metni kopyala'}
          aria-label="Metni kopyala"
        >
          <Copy className="h-3.5 w-3.5" />
        </button>
      </div>

      <button
        type="button"
        onClick={() => setOpen((value) => !value)}
        aria-expanded={open}
        className="mt-2 flex w-full items-center justify-between gap-2 text-left text-[11px] font-semibold text-zinc-500 hover:text-zinc-300 cursor-pointer"
      >
        <span>NEDEN?</span>
        <ChevronDown className={`h-3.5 w-3.5 transition-transform ${open ? 'rotate-180' : ''}`} />
      </button>

      {open && (
        <pre className="mt-2 whitespace-pre-wrap break-all rounded border border-zinc-800 bg-zinc-950/80 p-2 text-[10px] font-mono text-zinc-500 select-text">
          {whyText}
        </pre>
      )}
    </div>
  );
}
