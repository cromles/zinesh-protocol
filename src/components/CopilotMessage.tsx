import React from 'react';

export default function CopilotMessage({
  role,
  text,
  chain,
}: {
  role: 'user' | 'copilot';
  text: string;
  chain?: string;
}) {
  const isUser = role === 'user';
  return (
    <div
      className={`rounded-xl border px-4 py-3 ${
        isUser
          ? 'border-zinc-700 bg-zinc-900/80 ml-6'
          : 'border-cyan-500/20 bg-cyan-500/5 mr-6'
      }`}
    >
      <p className="text-[10px] uppercase tracking-wider font-bold text-zinc-500 mb-1">
        {isUser ? 'Siz' : 'Intelligence Copilot'}
      </p>
      <p className="text-sm text-zinc-200 whitespace-pre-wrap leading-relaxed select-text">{text}</p>
      {!isUser && chain ? (
        <p className="mt-2 font-mono text-[10px] text-zinc-600 select-text">{chain}</p>
      ) : null}
    </div>
  );
}
