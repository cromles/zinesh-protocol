import { useEffect, useState } from 'react';
import type { EscrowRoom, EscrowRoomStatus } from '../lib/escrowRoomApi';
import { getEscrowDebugSnapshot, subscribeEscrowDebug } from '../lib/escrowDebugTrace';
import { formatMoney } from '../lib/currencyFormat';

const FLOW_STEPS = [
  { id: 'connected', label: 'connected' },
  { id: 'negotiating', label: 'negotiating' },
  { id: 'terms_pending', label: 'terms_pending' },
  { id: 'locking', label: 'locking' },
  { id: 'locked', label: 'locked' },
  { id: 'completed', label: 'completed' },
] as const;

const STATUS_RANK: Record<string, number> = {
  negotiating: 1,
  terms_pending: 2,
  locking: 3,
  locked: 4,
  completion_pending: 5,
  settling: 6,
  completed: 7,
  disputed: 4,
  cancelled: 0,
  resolved: 7,
};

function stepReached(room: EscrowRoom | null, stepId: string): boolean {
  if (!room?.id) {
    return false;
  }
  if (stepId === 'connected') {
    return Boolean(room.employerUid && room.workerUid);
  }
  const rank = STATUS_RANK[room.status] ?? 0;
  const stepRank = STATUS_RANK[stepId] ?? 0;
  if (stepId === 'terms_pending') {
    return rank >= 2 || room.agreedAmountTry > 0;
  }
  return rank >= stepRank;
}

type Props = {
  room: EscrowRoom | null;
  availableBalance: number;
  workerAction?: string;
};

export default function EscrowRoomDebugPanel({ room, availableBalance, workerAction }: Props) {
  const [{ lastCall, lastError }, setTrace] = useState(getEscrowDebugSnapshot);

  useEffect(() => subscribeEscrowDebug(() => setTrace(getEscrowDebugSnapshot())), []);

  const lockedAmount =
    room && room.employerLockedTry > 0
      ? room.employerLockedTry
      : room && ['locking', 'locked', 'completion_pending', 'settling', 'completed'].includes(
            room.status,
          )
        ? room.agreedAmountTry
        : 0;

  return (
    <div className="rounded-xl border border-amber-500/30 bg-amber-500/[0.06] p-4 space-y-3 font-mono text-[11px] text-amber-100/90">
      <p className="text-[10px] uppercase tracking-wider text-amber-300/80 font-bold">Room status</p>
      <ul className="space-y-1">
        {FLOW_STEPS.map((step) => {
          const done = stepReached(room, step.id);
          return (
            <li key={step.id} className={done ? 'text-emerald-300' : 'text-zinc-500'}>
              {done ? '✓' : '○'} {step.label}
            </li>
          );
        })}
      </ul>

      <div className="grid grid-cols-2 gap-2 pt-1 border-t border-amber-500/20">
        <div>
          <p className="text-zinc-500">Employer balance</p>
          <p className="text-white">{formatMoney(availableBalance)}</p>
        </div>
        <div>
          <p className="text-zinc-500">Locked</p>
          <p className="text-white">{formatMoney(lockedAmount)}</p>
        </div>
        <div className="col-span-2">
          <p className="text-zinc-500">Worker</p>
          <p className="text-white">{workerAction ?? (room?.status === 'terms_pending' ? 'reviewing' : room?.status ?? '—')}</p>
        </div>
      </div>

      <div className="pt-1 border-t border-amber-500/20 space-y-1">
        <p className="text-zinc-500">Last API</p>
        <p className="text-white">{lastCall?.action ?? '—'}</p>
        <p className="text-zinc-400">
          HTTP {lastCall?.httpStatus ?? '—'} · {lastCall?.responseSummary ?? '—'}
        </p>
        {lastError ? <p className="text-red-300">Error: {lastError}</p> : null}
        {room ? (
          <p className="text-zinc-500">
            Room <span className="text-zinc-300">{room.id}</span> ·{' '}
            <span className="text-zinc-300">{room.status as EscrowRoomStatus}</span>
          </p>
        ) : null}
      </div>
    </div>
  );
}
