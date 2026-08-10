import {
  AlertTriangle,
  CheckCircle2,
  Clock,
  Lock,
  MessageSquare,
  ShieldCheck,
  User,
} from 'lucide-react';
import { formatMoney } from '../../lib/currencyFormat';
import { displayMemberTicket } from '../../lib/memberTicket';
import {
  buildEscrowSevenQuestions,
  ESCROW_DEAL_STAGES,
  ESCROW_DISPUTE_REVIEW_COPY,
  ESCROW_NEGOTIATING_WORKER_COPY,
  ESCROW_TRANSIENT_STATE_COPY,
  escrowDealActiveStage,
  escrowDealStageIndex,
  escrowPeerCard,
  escrowTermsPendingBanner,
  type EscrowDealStageId,
} from '../../lib/escrowDealViewModel';
import { escrowRoomNextAction, escrowRoomStatusLabel, type EscrowRoom } from '../../lib/escrowRoomApi';

type EscrowDealSummaryProps = {
  room: EscrowRoom;
  myName: string;
  availableBalance: number;
  onViewTrustProfile?: () => void;
};

function stageState(stageId: EscrowDealStageId, active: EscrowDealStageId): 'done' | 'current' | 'upcoming' {
  const activeIdx = escrowDealStageIndex(active);
  const idx = escrowDealStageIndex(stageId);
  if (idx < activeIdx) return 'done';
  if (idx === activeIdx) return 'current';
  return 'upcoming';
}

export function EscrowDealStatusSection({ room, myName, availableBalance }: EscrowDealSummaryProps) {
  const activeStage = escrowDealActiveStage(room.status);
  const activeIdx = escrowDealStageIndex(activeStage);
  const termsBanner = escrowTermsPendingBanner(room);
  const next = escrowRoomNextAction(room);

  return (
    <>
      <div className="overflow-hidden rounded-2xl border border-slate-800 bg-slate-950/80 p-4">
        <div className="mb-3 flex items-center justify-between gap-2">
          <p className="text-[10px] font-bold uppercase tracking-wider text-emerald-400">Gerçek anlaşma</p>
          <span className="truncate font-mono text-[10px] text-slate-500">#{room.id.slice(0, 8)}</span>
        </div>

        <p className="mb-3 text-xs font-semibold text-emerald-300 sm:hidden">
          {activeIdx + 1} / {ESCROW_DEAL_STAGES.length} — {escrowRoomStatusLabel(room.status, room.myRole)}
        </p>

        <div className="-mx-1 hidden overflow-x-auto pb-1 sm:block">
          <div className="grid min-w-[280px] grid-cols-5 gap-1 px-1">
            {ESCROW_DEAL_STAGES.map((stage) => {
              const state = stageState(stage.id, activeStage);
              return (
                <div
                  key={stage.id}
                  className={`rounded-lg border px-1 py-2 text-center text-[10px] font-bold leading-tight ${
                    state === 'current'
                      ? 'border-emerald-500 bg-emerald-500/15 text-emerald-300'
                      : state === 'done'
                        ? 'border-emerald-500/30 bg-emerald-950/30 text-emerald-400/80'
                        : 'border-slate-800 bg-slate-900/50 text-slate-600'
                  }`}
                  title={stage.description}
                >
                  {stage.label}
                </div>
              );
            })}
          </div>
        </div>

        <p className="mt-3 text-sm font-semibold text-white">
          {escrowRoomStatusLabel(room.status, room.myRole)}
        </p>
        {next && (
          <p className={`mt-1 text-xs ${next.urgent ? 'text-emerald-300' : 'text-slate-400'}`}>{next.hint}</p>
        )}
      </div>

      {termsBanner && (
        <div className="flex gap-3 rounded-2xl border border-amber-500/30 bg-amber-950/25 p-4">
          <Clock className="mt-0.5 h-5 w-5 shrink-0 text-amber-400" />
          <div>
            <p className="text-xs font-bold uppercase tracking-wider text-amber-300">Şartlar bekleniyor</p>
            <p className="mt-1 text-xs leading-relaxed text-amber-100/90">{termsBanner}</p>
          </div>
        </div>
      )}
    </>
  );
}

export function EscrowDealPeerSection({ room }: { room: EscrowRoom }) {
  const peer = escrowPeerCard(room);
  return (
    <div className="rounded-2xl border border-slate-800 bg-slate-900/60 p-4">
      <p className="mb-2 text-[10px] font-bold uppercase tracking-wider text-slate-500">Karşı taraf</p>
      <div className="flex items-center gap-3">
        <div className="flex h-10 w-10 items-center justify-center rounded-xl border border-emerald-500/30 bg-emerald-500/10 text-emerald-400">
          <User className="h-5 w-5" />
        </div>
        <div className="min-w-0">
          <p className="truncate text-sm font-bold text-white">{peer.name}</p>
          <p className="text-xs text-slate-400">
            {peer.roleLabel}
            {peer.ticket ? <span className="ml-1 font-mono text-emerald-400">ZN-{peer.ticket}</span> : null}
          </p>
        </div>
      </div>
    </div>
  );
}

export function EscrowNegotiatingWorkerGuide({
  myTicketNumber,
}: {
  myTicketNumber: string;
}) {
  return (
    <div className="rounded-2xl border border-sky-500/25 bg-sky-950/20 p-4 space-y-2">
      <p className="text-sm font-semibold text-sky-200">{ESCROW_NEGOTIATING_WORKER_COPY.title}</p>
      <p className="text-xs leading-relaxed text-slate-300">{ESCROW_NEGOTIATING_WORKER_COPY.description}</p>
      <p className="text-xs leading-relaxed text-slate-400">{ESCROW_NEGOTIATING_WORKER_COPY.guidance}</p>
      {myTicketNumber ? (
        <p className="text-[11px] text-slate-500 pt-1">
          ZN-ID&apos;niz:{' '}
          <span className="font-mono text-emerald-400">ZN-{displayMemberTicket(myTicketNumber)}</span>
        </p>
      ) : null}
    </div>
  );
}

export function EscrowTransientStateBanner({ room }: { room: EscrowRoom }) {
  if (room.status === 'locking') {
    const copy = ESCROW_TRANSIENT_STATE_COPY.locking;
    return (
      <div className="rounded-2xl border border-emerald-500/25 bg-emerald-950/20 p-4 space-y-1">
        <p className="text-sm font-semibold text-emerald-200">{copy.title}</p>
        <p className="text-xs leading-relaxed text-slate-400">{copy.description}</p>
      </div>
    );
  }
  if (room.status === 'settling') {
    const copy = ESCROW_TRANSIENT_STATE_COPY.settling;
    return (
      <div className="rounded-2xl border border-emerald-500/25 bg-emerald-950/20 p-4 space-y-1">
        <p className="text-sm font-semibold text-emerald-200">{copy.title}</p>
        <p className="text-xs leading-relaxed text-slate-400">{copy.description}</p>
      </div>
    );
  }
  return null;
}

export function EscrowDisputeReviewBlock() {
  return (
    <div className="rounded-2xl border border-amber-500/30 bg-amber-950/20 p-4 space-y-2">
      <p className="flex items-center gap-1.5 text-sm font-semibold text-amber-200">
        <AlertTriangle className="h-4 w-4 shrink-0" />
        {ESCROW_DISPUTE_REVIEW_COPY.title}
      </p>
      <p className="text-xs leading-relaxed text-slate-300">{ESCROW_DISPUTE_REVIEW_COPY.description}</p>
      <p className="text-xs leading-relaxed text-slate-400">{ESCROW_DISPUTE_REVIEW_COPY.nextStep}</p>
      <p className="text-[11px] text-slate-500">{ESCROW_DISPUTE_REVIEW_COPY.authority}</p>
    </div>
  );
}

export function EscrowDealEscrowSection({ room }: { room: EscrowRoom }) {
  const lockedAmount =
    room.employerLockedTry > 0 ? room.employerLockedTry : room.agreedAmountTry > 0 ? room.agreedAmountTry : 0;
  const show =
    room.status === 'locked' ||
    room.status === 'completion_pending' ||
    room.status === 'settling' ||
    room.status === 'disputed' ||
    lockedAmount > 0;
  if (!show) return null;

  return (
    <div className="rounded-2xl border border-emerald-500/25 bg-emerald-950/20 p-4">
      <div className="flex items-start justify-between gap-3">
        <div>
          <p className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-400">
            <Lock className="h-3.5 w-3.5" />
            Emanet durumu
          </p>
          {lockedAmount > 0 ? (
            <p className="mt-1 font-mono text-xl font-black text-emerald-300">{formatMoney(lockedAmount)}</p>
          ) : (
            <p className="mt-1 text-sm text-slate-300">Tutar bilgisi yükleniyor</p>
          )}
          <p className="mt-1 text-[11px] text-slate-400">
            {room.status === 'disputed'
              ? 'Anlaşmazlık sürecinde fonlar kilitli kalır.'
              : room.status === 'completed'
                ? 'Backend kayıtlarına göre sonuçlandı.'
                : 'Onay öncesi karşı tarafa aktarılmaz.'}
          </p>
        </div>
        {room.status === 'completed' && <CheckCircle2 className="h-6 w-6 shrink-0 text-emerald-400" />}
      </div>
    </div>
  );
}

export function EscrowTrustScoreNote({ onViewTrustProfile }: { onViewTrustProfile?: () => void }) {
  return (
    <div className="rounded-xl border border-slate-800 bg-slate-950/60 px-4 py-3">
      <p className="text-[11px] font-bold text-slate-300">Güven puanın</p>
      <p className="mt-1 text-xs leading-relaxed text-slate-500">
        Güven puanın tamamlanan anlaşmalarının sonuçlarına göre güncellenir.
      </p>
      {onViewTrustProfile ? (
        <button
          type="button"
          onClick={onViewTrustProfile}
          className="mt-2 text-xs font-semibold text-emerald-400 hover:text-emerald-300 cursor-pointer"
        >
          Güven puanını görüntüle →
        </button>
      ) : null}
    </div>
  );
}

export function EscrowDealSevenQuestionsSection({ room, myName, availableBalance }: EscrowDealSummaryProps) {
  const questions = buildEscrowSevenQuestions(room, myName, availableBalance);

  return (
    <>
      <div className="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 sm:hidden">
        <p className="mb-3 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-400">
          <ShieldCheck className="h-3.5 w-3.5" />
          7 soruda anlaşma özeti
        </p>
        <div className="space-y-2">
          {questions.map((q) => (
            <details
              key={q.id}
              className={`rounded-xl border ${
                q.emphasis ? 'border-emerald-500/25 bg-emerald-950/15' : 'border-slate-800/80 bg-slate-900/40'
              }`}
              open={q.emphasis}
            >
              <summary className="cursor-pointer list-none px-3 py-2.5 text-[11px] font-bold text-slate-300 [&::-webkit-details-marker]:hidden">
                {q.question}
              </summary>
              <p className="border-t border-slate-800/60 px-3 py-2 text-xs leading-relaxed text-slate-400">
                {q.answer}
              </p>
            </details>
          ))}
        </div>
      </div>

      <div className="hidden rounded-2xl border border-slate-800 bg-slate-950/70 p-4 sm:block">
        <p className="mb-3 flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-emerald-400">
          <ShieldCheck className="h-3.5 w-3.5" />
          7 soruda anlaşma özeti
        </p>
        <dl className="space-y-3">
          {questions.map((q) => (
            <div
              key={q.id}
              className={`rounded-xl border p-3 ${
                q.emphasis ? 'border-emerald-500/25 bg-emerald-950/15' : 'border-slate-800/80 bg-slate-900/40'
              }`}
            >
              <dt className="text-[11px] font-bold text-slate-300">{q.question}</dt>
              <dd className="mt-1 text-xs leading-relaxed text-slate-400">{q.answer}</dd>
            </div>
          ))}
        </dl>
      </div>
    </>
  );
}

export function EscrowDisputeRecordSection({ room }: { room: EscrowRoom }) {
  if (!room.dispute) return null;
  return (
    <div className="rounded-2xl border border-rose-500/25 bg-rose-950/20 p-4 space-y-2">
      <p className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wider text-rose-300">
        <AlertTriangle className="h-3.5 w-3.5" />
        Anlaşmazlık kaydı
      </p>
      <p className="text-xs text-slate-200">{room.dispute.reason}</p>
      {room.dispute.evidence?.trim() && (
        <p className="text-[11px] text-slate-400">Kanıt notu: {room.dispute.evidence}</p>
      )}
      <p className="text-[10px] text-slate-500">
        Durum: {room.dispute.status}
        {room.dispute.filedAt ? ` · ${room.dispute.filedAt}` : ''}
      </p>
    </div>
  );
}

export function EscrowMessagesSectionHeader({
  messageCount,
  expanded,
  onToggle,
}: {
  messageCount: number;
  expanded: boolean;
  onToggle: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onToggle}
      className="w-full flex items-center justify-between gap-2 rounded-xl border border-zinc-800 bg-zinc-950/50 px-4 py-3 text-left cursor-pointer"
    >
      <span className="flex items-center gap-2 text-sm text-zinc-300">
        <MessageSquare className="h-4 w-4 text-emerald-400" />
        Mesajlar
        {messageCount > 0 ? (
          <span className="rounded-full bg-emerald-500/20 px-2 py-0.5 text-[10px] font-bold text-emerald-300">
            {messageCount}
          </span>
        ) : (
          <span className="text-[11px] text-zinc-600">Karşı tarafla yazışın</span>
        )}
      </span>
      <span className={`text-xs text-zinc-500 transition ${expanded ? 'rotate-180' : ''}`}>▼</span>
    </button>
  );
}

/** Tam özet — geriye dönük uyumluluk */
export default function EscrowDealSummary(props: EscrowDealSummaryProps) {
  return (
    <div className="min-w-0 space-y-4">
      <EscrowDealStatusSection {...props} />
      <EscrowDealPeerSection room={props.room} />
      <EscrowDealEscrowSection room={props.room} />
      <EscrowDisputeRecordSection room={props.room} />
      <EscrowDealSevenQuestionsSection {...props} />
    </div>
  );
}
