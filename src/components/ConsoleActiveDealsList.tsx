import React from 'react';
import { ArrowRight, ChevronRight } from 'lucide-react';
import { formatMoney } from '../lib/currencyFormat';
import { formatEslesmeSinyalRelative } from '../lib/eslesmeSinyalApi';
import { escrowRoomStatusLabel, type EscrowRoom } from '../lib/escrowRoomApi';
import { escrowPeerCard } from '../lib/escrowDealViewModel';
import { roomLastUpdatedIso } from '../lib/consoleDashboardMetrics';
import { CONSOLE_CARD } from '../lib/consoleSkin';

function statusBadgeClass(status: EscrowRoom['status']): string {
  switch (status) {
    case 'terms_pending':
    case 'negotiating':
      return 'bg-sky-500/15 text-sky-300 border-sky-500/30';
    case 'locking':
    case 'locked':
    case 'completion_pending':
      return 'bg-amber-500/15 text-amber-200 border-amber-500/30';
    case 'disputed':
      return 'bg-red-500/15 text-red-300 border-red-500/30';
    case 'settling':
      return 'bg-violet-500/15 text-violet-200 border-violet-500/30';
    default:
      return 'bg-slate-800 text-slate-300 border-slate-700';
  }
}

interface ConsoleActiveDealsListProps {
  rooms: EscrowRoom[];
  loading: boolean;
  onOpenRoom: (roomId: string) => void;
  onViewAll: () => void;
}

export default function ConsoleActiveDealsList({
  rooms,
  loading,
  onOpenRoom,
  onViewAll,
}: ConsoleActiveDealsListProps) {
  return (
    <section className={`${CONSOLE_CARD} min-w-0 p-4 sm:p-6`} aria-labelledby="active-deals-heading">
      <div className="flex items-center justify-between gap-3">
        <h2 id="active-deals-heading" className="text-base font-bold text-white sm:text-lg">
          Aktif Anlaşmalarım
        </h2>
        {rooms.length > 0 && (
          <button
            type="button"
            onClick={onViewAll}
            className="inline-flex items-center gap-1 text-xs font-semibold text-emerald-400 transition hover:text-emerald-300"
          >
            Tümünü Gör
            <ChevronRight className="h-3.5 w-3.5" aria-hidden />
          </button>
        )}
      </div>

      {loading && rooms.length === 0 && (
        <p className="mt-6 text-center text-sm text-slate-500">Anlaşmalar yükleniyor…</p>
      )}

      {!loading && rooms.length === 0 && (
        <p className="mt-6 rounded-xl border border-dashed border-slate-800 bg-slate-950/50 px-4 py-8 text-center text-sm text-slate-500">
          Henüz aktif anlaşmanız yok.
        </p>
      )}

      {rooms.length > 0 && (
        <ul className="mt-4 space-y-3">
          {rooms.slice(0, 5).map((room) => {
            const peer = escrowPeerCard(room);
            const updatedIso = roomLastUpdatedIso(room);
            const relative = updatedIso ? formatEslesmeSinyalRelative(updatedIso) : null;
            const amount = room.agreedAmountTry > 0 ? room.agreedAmountTry : room.employerLockedTry;

            return (
              <li key={room.id}>
                <button
                  type="button"
                  onClick={() => onOpenRoom(room.id)}
                  className="group flex w-full min-w-0 flex-col gap-2.5 rounded-2xl border border-slate-800 bg-slate-950/60 p-3.5 text-left transition hover:border-emerald-500/30 hover:bg-slate-900/80 sm:gap-3 sm:p-4"
                >
                  <div className="flex min-w-0 items-start justify-between gap-2">
                    <p className="min-w-0 flex-1 truncate text-sm font-semibold text-white">
                      {room.title?.trim() || 'Başlıksız anlaşma'}
                    </p>
                    <span
                      className={`max-w-[45%] shrink-0 truncate rounded-full border px-2 py-0.5 text-[10px] font-semibold ${statusBadgeClass(room.status)}`}
                    >
                      {escrowRoomStatusLabel(room.status, room.myRole)}
                    </span>
                  </div>
                  <p className="truncate text-xs text-slate-400">
                    {peer.ticket ? `ZN-${peer.ticket}` : '—'}
                    {peer.name ? ` · ${peer.name}` : ''}
                  </p>
                  <div className="flex items-center justify-between gap-2">
                    <div className="min-w-0">
                      {amount > 0 ? (
                        <p className="truncate text-sm font-bold tabular-nums text-emerald-300">
                          {formatMoney(amount)}
                        </p>
                      ) : (
                        <p className="text-xs text-slate-500">Tutar belirlenmedi</p>
                      )}
                      {relative && <p className="text-[10px] text-slate-500">{relative}</p>}
                    </div>
                    <span className="hidden font-mono text-[10px] text-slate-600 sm:inline">{room.id}</span>
                    <ArrowRight className="h-4 w-4 shrink-0 text-slate-600 transition group-hover:text-emerald-400 sm:hidden" aria-hidden />
                  </div>
                </button>
              </li>
            );
          })}
        </ul>
      )}
    </section>
  );
}
