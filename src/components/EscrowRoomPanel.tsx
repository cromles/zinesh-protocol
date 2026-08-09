import React, { useCallback, useEffect, useRef, useState } from 'react';
import {
  ArrowLeft,
  Check,
  ChevronDown,
  Lock,
  Plus,
  Send,
} from 'lucide-react';
import { formatMoney } from '../lib/currencyFormat';
import { CURRENCY_NAME } from '../lib/productMode';
import {
  acceptEscrowTerms,
  confirmEscrowComplete,
  connectEscrowRoom,
  counterEscrowOffer,
  escrowRoomNextAction,
  escrowRoomStatusLabel,
  fetchEscrowRoomDetail,
  fetchEscrowRooms,
  fetchEscrowRoomTimeline,
  fileEscrowDispute,
  proposeEscrowTerms,
  rejectEscrowTerms,
  requestEscrowChanges,
  requestEscrowRoomCancel,
  sendEscrowRoomMessage,
  type EscrowRoom,
  type EscrowRoomMessage,
  type EscrowRoomRole,
  type EscrowTimelineEntry,
} from '../lib/escrowRoomApi';
import { fetchWalletState } from '../lib/walletApi';
import {
  CONSOLE_POLL_LIST_MS,
  CONSOLE_POLL_ROOM_DETAIL_MS,
} from '../lib/consolePoll';
import { getSessionToken, getSession } from '../lib/auth';
import { ContractVerificationError } from '../lib/contractVerification';
import { CONTRACT_MIN_CHARS, ESCROW_PARTY } from '../lib/plainLanguage';
import EslesmeSinyalCenter from './EslesmeSinyalCenter';
import EscrowRoomDebugPanel from './EscrowRoomDebugPanel';
import RiskIntelligencePanel from './RiskIntelligencePanel';
import TrustTimeline from './TrustTimeline';
import CopilotPanel from './CopilotPanel';
import {
  fetchRoomRiskEngine,
  riskEngineErrorKind,
  type RiskEngineErrorKind,
  type RiskObservation,
} from '../lib/riskEngineApi';
import { isDemoUiEnabled } from '../lib/demoMode';
import { displayMemberTicket } from '../lib/memberTicket';
import { isInsufficientBalanceMessage } from '../lib/insufficientBalance';
import type { EslesmeSinyal } from '../lib/eslesmeSinyalApi';
import EscrowDealSummary from './escrow/EscrowDealSummary';
import {
  CONSOLE_CARD,
  CONSOLE_INPUT,
  CONSOLE_SURFACE,
} from '../lib/consoleSkin';

const INPUT = `${CONSOLE_INPUT} min-h-[48px] text-base rounded-2xl`;

const ROOM_LIST_POLL_MS = CONSOLE_POLL_LIST_MS;
const ROOM_DETAIL_POLL_MS = CONSOLE_POLL_ROOM_DETAIL_MS;

function formatEscrowTimestamp(iso?: string): string | null {
  if (!iso?.trim()) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleString('tr-TR', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
}

function roomPollKey(room: EscrowRoom): string {
  return [
    room.status,
    room.agreedAmountTry,
    room.title,
    room.description ?? '',
    room.employerConfirmedComplete,
    room.workerConfirmedComplete,
    room.employerRequestsCollateral,
    room.workerRequestsCollateral,
    room.employerCancelRequested,
    room.workerCancelRequested,
    room.termsProposedBy ?? 'employer',
    room.termsChangeRequested,
  ].join('|');
}

function messagesPollKey(msgs: EscrowRoomMessage[]): string {
  if (msgs.length === 0) return '';
  const last = msgs[msgs.length - 1];
  return `${msgs.length}:${last.id}:${last.createdAt}`;
}

function formatTimelineDate(iso?: string): string | null {
  if (!iso?.trim()) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  return d.toLocaleString('tr-TR', {
    day: 'numeric',
    month: 'long',
    hour: '2-digit',
    minute: '2-digit',
  });
}

/** Görüntüleme amaçlı — event_type kullanıcıya gösterilmez */
function timelineEntryIcon(event: string): string {
  const map: Record<string, string> = {
    contract_created: '📝',
    terms_proposed: '📝',
    terms_updated: '📋',
    terms_rejected: '↩️',
    changes_requested: '✏️',
    counter_offer_created: '📋',
    terms_accepted: '🤝',
    contract_finalized: '🤝',
    escrow_funded: '💰',
    escrow_locked: '🔒',
    escrow_release_requested: '🔓',
    settlement_started: '⏳',
    settlement_completed: '✅',
    settlement_failed: '⚠️',
    escrow_recovered: '🔄',
    dispute_opened: '⚖️',
    dispute_deposit_paid: '💳',
    dispute_resolved: '⚖️',
    dispute_deposit_refunded: '↩️',
    dispute_deposit_forfeited: '⚖️',
  };
  return map[event] ?? '•';
}

function EscrowRoomTimelineView({
  entries,
  loading,
}: {
  entries: EscrowTimelineEntry[];
  loading: boolean;
}) {
  return (
    <div className="rounded-2xl border border-zinc-800 bg-[#09090e] p-5">
      <p className="text-sm font-semibold text-white mb-3">İşlem Geçmişi</p>
      {loading && entries.length === 0 ? (
        <p className="text-xs text-zinc-600">Yükleniyor…</p>
      ) : entries.length === 0 ? (
        <p className="text-xs text-zinc-600 leading-relaxed">
          Henüz kayıtlı işlem geçmişi yok. Yeni işlemler burada görünecek.
        </p>
      ) : (
        <ol className="space-y-0">
          {entries.map((entry, index) => {
            const when = formatTimelineDate(entry.date);
            const isLast = index === entries.length - 1;
            return (
              <li key={entry.id || `${entry.date}-${index}`} className="flex gap-3">
                <div className="flex flex-col items-center shrink-0 pt-0.5">
                  <span className="text-base leading-none" aria-hidden>
                    {timelineEntryIcon(entry.event)}
                  </span>
                  {!isLast && <span className="w-px flex-1 min-h-[1.25rem] bg-zinc-800 mt-2" />}
                </div>
                <div className={`min-w-0 flex-1 ${isLast ? 'pb-0' : 'pb-4'}`}>
                  <p className="text-sm text-zinc-200 leading-snug">{entry.description}</p>
                  {when && <p className="text-[11px] text-zinc-500 mt-1">{when}</p>}
                </div>
              </li>
            );
          })}
        </ol>
      )}
    </div>
  );
}

export interface EscrowRoomPanelProps {
  /** start = yeni iş başlat, my-deals = emanet listesi */
  variant?: 'start' | 'my-deals';
  myTicketNumber: string;
  availableBalance: number;
  activeRoomId?: string | null;
  onActiveRoomIdChange?: (roomId: string | null) => void;
  onWalletRefresh?: (wallet: import('../lib/walletApi').WalletState) => void;
  /** Kasa → yatır sekmesine git (yetersiz bakiye) */
  onGoToDeposit?: () => void;
  /** Yeni sözleşme için 3 kademeli doğrulama tamam mı */
  canCreateContract?: boolean;
  /** Doğrulama eksikse modal aç */
  onBlockedCreateContract?: () => void;
}

function PanelError({
  message,
  onGoToDeposit,
}: {
  message: string;
  onGoToDeposit?: () => void;
}) {
  const insufficient = isInsufficientBalanceMessage(message);
  return (
    <div className="text-sm text-red-300 bg-red-500/10 border border-red-500/20 rounded-xl px-4 py-3 space-y-2">
      <p className="font-semibold text-red-200">{insufficient ? 'Yetersiz bakiye.' : message}</p>
      {insufficient && message.trim() !== 'Yetersiz bakiye.' && (
        <p className="text-xs text-red-200/80 leading-relaxed">{message}</p>
      )}
      {insufficient && onGoToDeposit && (
        <button
          type="button"
          onClick={onGoToDeposit}
          className="inline-flex items-center gap-1 text-sm font-semibold text-emerald-300 hover:text-emerald-200 underline-offset-2 hover:underline cursor-pointer"
        >
          Bakiye ekle →
        </button>
      )}
    </div>
  );
}

export default function EscrowRoomPanel({
  variant = 'my-deals',
  myTicketNumber,
  availableBalance,
  activeRoomId: activeRoomIdProp,
  onActiveRoomIdChange,
  onWalletRefresh,
  onGoToDeposit,
  canCreateContract = true,
  onBlockedCreateContract,
}: EscrowRoomPanelProps) {
  const [rooms, setRooms] = useState<EscrowRoom[]>([]);
  const [internalRoomId, setInternalRoomId] = useState<string | null>(null);
  const activeRoomId = activeRoomIdProp !== undefined ? activeRoomIdProp : internalRoomId;
  const setActiveRoomId = (id: string | null) => {
    onActiveRoomIdChange?.(id);
    setInternalRoomId(id);
  };

  const [activeRoom, setActiveRoom] = useState<EscrowRoom | null>(null);
  const [messages, setMessages] = useState<EscrowRoomMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [showNewConnect, setShowNewConnect] = useState(variant === 'start');
  const [showMessages, setShowMessages] = useState(false);
  const messagesEndRef = useRef<HTMLDivElement>(null);
  const scrollMessagesOnNextRender = useRef(false);
  const roomPollKeyRef = useRef('');
  const messagesPollKeyRef = useRef('');

  const [peerTicket, setPeerTicket] = useState('');
  const [connectRole, setConnectRole] = useState<EscrowRoomRole>('employer');
  const [connectLoading, setConnectLoading] = useState(false);

  const [messageBody, setMessageBody] = useState('');
  const [sendLoading, setSendLoading] = useState(false);

  const [offerTitle, setOfferTitle] = useState('');
  const [offerAmount, setOfferAmount] = useState<number | ''>('');
  const [offerContract, setOfferContract] = useState('');
  const [offerLoading, setOfferLoading] = useState(false);

  const [acceptLoading, setAcceptLoading] = useState(false);
  const [confirmLoading, setConfirmLoading] = useState(false);
  const [cancelLoading, setCancelLoading] = useState(false);
  const [disputeReason, setDisputeReason] = useState('');
  const [disputeLoading, setDisputeLoading] = useState(false);
  const [showDispute, setShowDispute] = useState(false);

  const [rejectReason, setRejectReason] = useState('');
  const [rejectLoading, setRejectLoading] = useState(false);
  const [showReject, setShowReject] = useState(false);
  const [changeNote, setChangeNote] = useState('');
  const [changesLoading, setChangesLoading] = useState(false);
  const [showRequestChanges, setShowRequestChanges] = useState(false);
  const [showCounterOffer, setShowCounterOffer] = useState(false);
  const [counterLoading, setCounterLoading] = useState(false);

  const [timeline, setTimeline] = useState<EscrowTimelineEntry[]>([]);
  const [timelineLoading, setTimelineLoading] = useState(false);
  const [riskObservations, setRiskObservations] = useState<RiskObservation[]>([]);
  const [riskEngineLoading, setRiskEngineLoading] = useState(false);
  const [riskEngineErrorKind, setRiskEngineErrorKind] = useState<RiskEngineErrorKind | null>(null);
  const activeRoomIdRef = useRef<string | null>(null);

  const loadRiskEngine = useCallback(async (roomId: string) => {
    const actorId = getSession()?.uid;
    activeRoomIdRef.current = roomId;
    setRiskEngineLoading(true);
    setRiskEngineErrorKind(null);
    try {
      const pkg = await fetchRoomRiskEngine(roomId, actorId);
      if (activeRoomIdRef.current !== roomId) return;
      setRiskObservations(pkg.observationRows);
      setRiskEngineErrorKind(null);
    } catch (err) {
      if (activeRoomIdRef.current !== roomId) return;
      setRiskObservations([]);
      setRiskEngineErrorKind(riskEngineErrorKind(err));
    } finally {
      if (activeRoomIdRef.current === roomId) {
        setRiskEngineLoading(false);
      }
    }
  }, []);

  const loadTimeline = useCallback(async (roomId: string) => {
    setTimelineLoading(true);
    try {
      const { timeline: entries } = await fetchEscrowRoomTimeline(roomId);
      setTimeline(entries);
    } catch {
      setTimeline([]);
    } finally {
      setTimelineLoading(false);
    }
  }, []);

  const loadRooms = useCallback(async () => {
    try {
      const list = await fetchEscrowRooms();
      setRooms((prev) => {
        const prevKey = prev.map((r) => `${r.id}:${r.status}:${r.agreedAmountTry}`).join(';');
        const nextKey = list.map((r) => `${r.id}:${r.status}:${r.agreedAmountTry}`).join(';');
        return prevKey === nextKey ? prev : list;
      });
      setError('');
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Liste yüklenemedi.');
    }
  }, []);

  const refreshRoomDetail = useCallback(async (roomId: string, silent = false) => {
    if (!silent) setLoading(true);
    void loadTimeline(roomId);
    void loadRiskEngine(roomId);
    try {
      const { room, messages: msgs } = await fetchEscrowRoomDetail(roomId);
      const nextRoomKey = roomPollKey(room);
      const nextMsgKey = messagesPollKey(msgs);
      if (nextRoomKey !== roomPollKeyRef.current) {
        roomPollKeyRef.current = nextRoomKey;
        setActiveRoom(room);
      }
      if (nextMsgKey !== messagesPollKeyRef.current) {
        messagesPollKeyRef.current = nextMsgKey;
        setMessages(msgs);
      }
      setError('');
      return true;
    } catch (e) {
      if (!silent) setError(e instanceof Error ? e.message : 'Detay yüklenemedi.');
      return false;
    } finally {
      if (!silent) setLoading(false);
    }
  }, [loadTimeline, loadRiskEngine]);

  const openRoom = useCallback(
    async (roomId: string) => {
      setActiveRoomId(roomId);
      setError('');
      setLoading(true);
      setShowMessages(false);
      roomPollKeyRef.current = '';
      messagesPollKeyRef.current = '';
      void loadTimeline(roomId);
      void loadRiskEngine(roomId);
      try {
        const { room, messages: msgs } = await fetchEscrowRoomDetail(roomId);
        roomPollKeyRef.current = roomPollKey(room);
        messagesPollKeyRef.current = messagesPollKey(msgs);
        setActiveRoom(room);
        setMessages(msgs);
        setOfferTitle(room.title || '');
        setOfferAmount(room.agreedAmountTry > 0 ? room.agreedAmountTry : '');
      } catch (e) {
        setError(e instanceof Error ? e.message : 'Detay yüklenemedi.');
        setActiveRoomId(null);
      } finally {
        setLoading(false);
      }
    },
    [setActiveRoomId, loadTimeline, loadRiskEngine],
  );

  const closeRoom = () => {
    activeRoomIdRef.current = null;
    setActiveRoomId(null);
    setActiveRoom(null);
    setMessages([]);
    setTimeline([]);
    setTimelineLoading(false);
    setRiskObservations([]);
    setRiskEngineLoading(false);
    setRiskEngineErrorKind(null);
    setError('');
    setShowDispute(false);
  };

  useEffect(() => {
    loadRooms();
    const tick = () => {
      if (document.visibilityState === 'hidden') return;
      loadRooms();
    };
    const t = window.setInterval(tick, ROOM_LIST_POLL_MS);
    return () => window.clearInterval(t);
  }, [loadRooms]);

  useEffect(() => {
    if (!activeRoomId) {
      setActiveRoom(null);
      setMessages([]);
      setTimeline([]);
      setTimelineLoading(false);
      roomPollKeyRef.current = '';
      messagesPollKeyRef.current = '';
      return;
    }
    const detailPoll = () => {
      if (document.visibilityState === 'hidden') return;
      void refreshRoomDetail(activeRoomId, true);
    };
    const initialDelay = window.setTimeout(detailPoll, Math.floor(ROOM_DETAIL_POLL_MS / 2));
    const t = window.setInterval(detailPoll, ROOM_DETAIL_POLL_MS);
    return () => {
      window.clearTimeout(initialDelay);
      window.clearInterval(t);
    };
  }, [activeRoomId, refreshRoomDetail]);

  useEffect(() => {
    if (!showMessages || !scrollMessagesOnNextRender.current) return;
    scrollMessagesOnNextRender.current = false;
    messagesEndRef.current?.scrollIntoView({ behavior: 'auto', block: 'nearest' });
  }, [messages, showMessages]);

  const tryOpenNewConnect = () => {
    if (!canCreateContract) {
      onBlockedCreateContract?.();
      return;
    }
    setShowNewConnect(true);
  };

  const handleConnect = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!canCreateContract) {
      onBlockedCreateContract?.();
      return;
    }
    setConnectLoading(true);
    setError('');
    try {
      const room = await connectEscrowRoom(peerTicket.trim(), connectRole);
      await loadRooms();
      setShowNewConnect(false);
      setPeerTicket('');
      await openRoom(room.id);
    } catch (err) {
      if (err instanceof ContractVerificationError) {
        onBlockedCreateContract?.();
        return;
      }
      setError(err instanceof Error ? err.message : 'Bağlantı kurulamadı.');
    } finally {
      setConnectLoading(false);
    }
  };

  const handlePropose = async (e: React.FormEvent) => {
    e.preventDefault();
    const contract = offerContract.trim();
    if (!activeRoomId || offerAmount === '' || offerAmount < 1 || !offerTitle.trim()) {
      setError('Başlık ve geçerli bir tutar girin.');
      return;
    }
    if (contract.length < CONTRACT_MIN_CHARS) {
      setError(
        `Sözleşme metni en az ${CONTRACT_MIN_CHARS} karakter olmalı. Ne isteniyor ve ne teslim edilecek net yazın.`,
      );
      return;
    }
    if (typeof offerAmount === 'number' && offerAmount > availableBalance + 0.009) {
      setError(
        `Yetersiz bakiye. Anlaşma için en az ${formatMoney(offerAmount)} kullanılabilir bakiye gerekir (şu an ${formatMoney(availableBalance)}).`,
      );
      return;
    }
    setOfferLoading(true);
    setError('');
    try {
      const room = await proposeEscrowTerms(
        activeRoomId,
        offerAmount,
        offerTitle.trim(),
        contract,
        false,
      );
      setActiveRoom(room);
      setOfferContract(contract);
      await refreshRoomDetail(activeRoomId, true);
      await loadRooms();
    } catch (err) {
      if (err instanceof ContractVerificationError) {
        onBlockedCreateContract?.();
        return;
      }
      setError(err instanceof Error ? err.message : 'Teklif gönderilemedi.');
    } finally {
      setOfferLoading(false);
    }
  };

  const refreshWallet = useCallback(async (wallet?: import('../lib/walletApi').WalletState | null) => {
    if (!onWalletRefresh) return;
    try {
      const fresh = wallet ?? (await fetchWalletState());
      onWalletRefresh(fresh);
    } catch {
      if (wallet) onWalletRefresh(wallet);
    }
  }, [onWalletRefresh]);

  const handleAccept = async () => {
    if (!activeRoomId) return;
    setAcceptLoading(true);
    setError('');
    try {
      const { room, wallet } = await acceptEscrowTerms(activeRoomId, false);
      setActiveRoom(room);
      await refreshWallet(wallet);
      await refreshRoomDetail(activeRoomId, true);
      await loadRooms();
    } catch (err) {
      if (err instanceof ContractVerificationError) {
        onBlockedCreateContract?.();
        return;
      }
      setError(err instanceof Error ? err.message : 'Onaylanamadı.');
    } finally {
      setAcceptLoading(false);
    }
  };

  const handleConfirm = async () => {
    if (!activeRoomId) return;
    setConfirmLoading(true);
    setError('');
    try {
      const { room, wallet } = await confirmEscrowComplete(activeRoomId);
      setActiveRoom(room);
      await refreshWallet(wallet);
      await refreshRoomDetail(activeRoomId, true);
      await loadRooms();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Onay alınamadı.');
    } finally {
      setConfirmLoading(false);
    }
  };

  const handleCancel = async () => {
    if (!activeRoomId || !window.confirm('İptal talebi gönderilsin mi? Karşı taraf da onaylamalı.')) return;
    setCancelLoading(true);
    try {
      const { room, wallet } = await requestEscrowRoomCancel(activeRoomId);
      setActiveRoom(room);
      await refreshWallet(wallet);
      await loadRooms();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'İptal gönderilemedi.');
    } finally {
      setCancelLoading(false);
    }
  };

  const handleSinyalEylem = async (sinyal: EslesmeSinyal) => {
    if (sinyal.tip === 'is_tamamlandi') {
      const { room, wallet } = await confirmEscrowComplete(sinyal.eslesme_id);
      setActiveRoom(room);
      await refreshWallet(wallet);
      await refreshRoomDetail(sinyal.eslesme_id, true);
      await loadRooms();
      return;
    }
    if (sinyal.tip === 'iptal_istegi') {
      const { room, wallet } = await requestEscrowRoomCancel(sinyal.eslesme_id);
      setActiveRoom(room);
      await refreshWallet(wallet);
      await loadRooms();
    }
  };

  const handleDispute = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeRoomId || !disputeReason.trim()) return;
    setDisputeLoading(true);
    try {
      const room = await fileEscrowDispute(activeRoomId, disputeReason.trim());
      setActiveRoom(room);
      setShowDispute(false);
      await loadRooms();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Şikayet gönderilemedi.');
    } finally {
      setDisputeLoading(false);
    }
  };

  const handleReject = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeRoomId) return;
    setRejectLoading(true);
    setError('');
    try {
      const room = await rejectEscrowTerms(activeRoomId, rejectReason.trim());
      setActiveRoom(room);
      setShowReject(false);
      setRejectReason('');
      await refreshRoomDetail(activeRoomId, true);
      await loadRooms();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Teklif reddedilemedi.');
    } finally {
      setRejectLoading(false);
    }
  };

  const handleRequestChanges = async (e: React.FormEvent) => {
    e.preventDefault();
    if (!activeRoomId || changeNote.trim().length < 10) return;
    setChangesLoading(true);
    setError('');
    try {
      const room = await requestEscrowChanges(activeRoomId, changeNote.trim());
      setActiveRoom(room);
      setShowRequestChanges(false);
      setChangeNote('');
      await refreshRoomDetail(activeRoomId, true);
      await loadRooms();
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Talep iletilemedi.');
    } finally {
      setChangesLoading(false);
    }
  };

  const handleCounterOffer = async (e: React.FormEvent) => {
    e.preventDefault();
    const contract = offerContract.trim();
    if (!activeRoomId || offerAmount === '' || offerAmount < 1 || !offerTitle.trim()) return;
    if (contract.length < CONTRACT_MIN_CHARS) {
      setError(`Sözleşme metni en az ${CONTRACT_MIN_CHARS} karakter olmalı.`);
      return;
    }
    setCounterLoading(true);
    setError('');
    try {
      const room = await counterEscrowOffer(
        activeRoomId,
        offerAmount,
        offerTitle.trim(),
        contract,
        false,
      );
      setActiveRoom(room);
      setShowCounterOffer(false);
      await refreshRoomDetail(activeRoomId, true);
      await loadRooms();
    } catch (err) {
      if (err instanceof ContractVerificationError) {
        onBlockedCreateContract?.();
        return;
      }
      setError(err instanceof Error ? err.message : 'Karşı teklif gönderilemedi.');
    } finally {
      setCounterLoading(false);
    }
  };

  const renderConnectForm = () => (
    <form onSubmit={handleConnect} className={`${CONSOLE_SURFACE} p-5 space-y-4`}>
      <div>
        <p className="text-sm font-semibold text-white">Karşı tarafın ZN-ID numarası</p>
        <p className="text-xs text-slate-500 mt-0.5">
          Dışarıda anlaştığınız kişinin üye numarasını girin. Eşleştirme veya öneri yoktur.
        </p>
      </div>
      <input
        type="text"
        inputMode="numeric"
        value={peerTicket}
        onChange={(e) => setPeerTicket(e.target.value.replace(/\D/g, '').slice(0, 6))}
        placeholder="22595"
        className={`${INPUT} font-mono tracking-wide`}
        required
      />
      <div className="grid grid-cols-2 gap-2">
        <button
          type="button"
          onClick={() => setConnectRole('employer')}
          className={`min-h-[48px] rounded-xl text-sm font-semibold border cursor-pointer ${
            connectRole === 'employer'
              ? 'bg-emerald-600 border-emerald-500 text-white'
              : 'border-slate-700 text-slate-400'
          }`}
        >
          {ESCROW_PARTY.employerBtn}
        </button>
        <button
          type="button"
          onClick={() => setConnectRole('worker')}
          className={`min-h-[48px] rounded-xl text-sm font-semibold border cursor-pointer ${
            connectRole === 'worker'
              ? 'bg-sky-600 border-sky-500 text-white'
              : 'border-slate-700 text-slate-400'
          }`}
        >
          {ESCROW_PARTY.workerBtn}
        </button>
      </div>
      <button
        type="submit"
        disabled={connectLoading || peerTicket.trim().length < 4}
        className="w-full min-h-[52px] rounded-2xl bg-white text-black font-bold text-base disabled:opacity-50 cursor-pointer"
      >
        {connectLoading ? 'Bağlanıyor…' : 'Bağlan'}
      </button>
      <p className="text-[11px] text-slate-500 text-center">
        Numaranız: <span className="font-mono text-emerald-400">ZN-{displayMemberTicket(myTicketNumber)}</span>
      </p>
    </form>
  );

  const renderRoomList = () => (
    <div className="space-y-3">
      {rooms.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-slate-800 p-8 text-center">
          <p className="text-sm text-slate-400">Henüz anlaşma yok.</p>
          <p className="text-xs text-slate-600 mt-1">Karşı tarafın ZN-ID numarasıyla yeni iş başlatın.</p>
        </div>
      ) : (
        rooms.map((room) => {
          const action = escrowRoomNextAction(room);
          return (
            <button
              key={room.id}
              type="button"
              onClick={() => openRoom(room.id)}
              className={`w-full text-left rounded-2xl border p-4 transition cursor-pointer ${
                action?.urgent
                  ? 'border-emerald-500/30 bg-emerald-500/[0.06] hover:border-emerald-500/50'
                  : 'border-slate-800 bg-slate-950/70 hover:border-slate-700'
              }`}
            >
              <div className="flex justify-between gap-3 items-start">
                <div className="min-w-0">
                  <p className="font-semibold text-white truncate">{room.title || 'Görüşme'}</p>
                  <p className="text-xs text-zinc-500 mt-0.5">
                    {room.myRole === 'employer' ? ESCROW_PARTY.employer : ESCROW_PARTY.worker}
                    {room.agreedAmountTry > 0 ? ` · ${formatMoney(room.agreedAmountTry)}` : ''}
                  </p>
                </div>
                <span className={`text-[10px] font-bold shrink-0 ${action?.urgent ? 'text-emerald-300' : 'text-zinc-400'}`}>
                  {escrowRoomStatusLabel(room.status, room.myRole)}
                </span>
              </div>
              {action?.hint && (
                <p className={`text-xs mt-2 ${action.urgent ? 'text-emerald-200/90' : 'text-zinc-500'}`}>
                  {action.hint}
                </p>
              )}
            </button>
          );
        })
      )}
    </div>
  );

  if (activeRoomId && !activeRoom) {
    return (
      <div className="mx-auto w-full min-w-0 max-w-lg space-y-4 escrow-stable-scroll">
        <button
          type="button"
          onClick={closeRoom}
          className="text-sm text-zinc-400 hover:text-white flex items-center gap-1.5 cursor-pointer py-1"
        >
          <ArrowLeft className="h-4 w-4" /> Listeye dön
        </button>
        <p className="text-sm text-zinc-500 text-center py-10">Görüşme yükleniyor…</p>
      </div>
    );
  }

  if (activeRoomId && activeRoom) {
    const role = activeRoom.myRole;
    const isEmployer = role === 'employer';
    const isWorker = role === 'worker';
    const canPropose = isEmployer && ['negotiating', 'terms_pending'].includes(activeRoom.status)
      && activeRoom.termsProposedBy !== 'worker';
    const canAcceptEmployerOffer = isWorker && activeRoom.status === 'terms_pending'
      && activeRoom.agreedAmountTry > 0 && activeRoom.termsProposedBy !== 'worker';
    const canAcceptWorkerCounter = isEmployer && activeRoom.status === 'terms_pending'
      && activeRoom.agreedAmountTry > 0 && activeRoom.termsProposedBy === 'worker';
    const canAccept = canAcceptEmployerOffer || canAcceptWorkerCounter;
    const workerDebugAction =
      activeRoom.status === 'locked' || activeRoom.status === 'completion_pending'
        ? 'accepted'
        : activeRoom.status === 'terms_pending' && isEmployer
          ? 'awaiting accept'
          : activeRoom.status === 'terms_pending' && isWorker
            ? 'reviewing'
            : activeRoom.status === 'locking'
              ? 'locking'
              : undefined;
    const canNegotiateTerms = isWorker && activeRoom.status === 'terms_pending'
      && activeRoom.agreedAmountTry > 0 && activeRoom.termsProposedBy !== 'worker';
    const lockedPrincipal = activeRoom.employerLockedTry > 0
      ? activeRoom.employerLockedTry
      : activeRoom.agreedAmountTry;
    const disputeDepositPreview = lockedPrincipal > 0
      ? Math.round(lockedPrincipal * 0.01 * 100) / 100
      : 0;
    const disputeDepositTry = activeRoom.disputeDepositTry ?? disputeDepositPreview;
    const canConfirm = ['locked', 'completion_pending'].includes(activeRoom.status);
    const alreadyConfirmed =
      (isEmployer && activeRoom.employerConfirmedComplete) ||
      (isWorker && activeRoom.workerConfirmedComplete);
    const isDone = ['completed', 'cancelled', 'resolved'].includes(activeRoom.status);
    const termsProposedLabel = formatEscrowTimestamp(activeRoom.termsProposedAt);
    const lockedLabel = formatEscrowTimestamp(activeRoom.lockedAt);
    const createdLabel = formatEscrowTimestamp(activeRoom.createdAt);
    const myName = getSession()?.name ?? '';

    return (
      <div className="mx-auto w-full min-w-0 max-w-lg space-y-4 escrow-stable-scroll">
        <button
          type="button"
          onClick={closeRoom}
          className="text-sm text-slate-400 hover:text-white flex items-center gap-1.5 cursor-pointer py-1"
        >
          <ArrowLeft className="h-4 w-4" /> Listeye dön
        </button>

        <div className={`${CONSOLE_SURFACE} p-5 space-y-2`}>
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-lg font-bold text-white">{activeRoom.title || 'İş görüşmesi'}</p>
              <p className="text-sm text-slate-400">
                {escrowRoomStatusLabel(activeRoom.status, role)}
                {activeRoom.agreedAmountTry > 0 && (
                  <span className="text-white font-semibold"> · {formatMoney(activeRoom.agreedAmountTry)}</span>
                )}
              </p>
            </div>
            <EslesmeSinyalCenter
              sessionToken={getSessionToken() ?? undefined}
              eslesmeId={activeRoom.id}
              onEylem={handleSinyalEylem}
            />
          </div>
        </div>

        <EscrowDealSummary
          room={activeRoom}
          myName={myName}
          availableBalance={availableBalance}
        />

        {isDemoUiEnabled() ? (
          <EscrowRoomDebugPanel
            room={activeRoom}
            availableBalance={availableBalance}
            workerAction={workerDebugAction}
          />
        ) : null}

        <div className="rounded-2xl border border-emerald-500/25 bg-emerald-950/20 p-5 space-y-2">
          <p className="font-mono text-[10px] uppercase tracking-wider text-emerald-400 font-bold">
            Yazılı sözleşme
          </p>
          {(activeRoom.description || '').trim() ? (
            <>
              <p className="text-sm text-slate-200 whitespace-pre-wrap leading-relaxed">
                {activeRoom.description}
              </p>
              {(termsProposedLabel || lockedLabel || createdLabel) && (
                <div className="pt-2 space-y-1 text-[11px] text-slate-500 font-mono">
                  {createdLabel && <p>Eşleşme: {createdLabel}</p>}
                  {termsProposedLabel && <p>Sözleşme teklifi: {termsProposedLabel}</p>}
                  {lockedLabel && <p>Kilitlenme (onay): {lockedLabel}</p>}
                </div>
              )}
              {canAcceptEmployerOffer && (
                <p className="text-xs text-slate-500 pt-1">
                  Onayladığınızda bu metin ve tutar kilitlenir. Beğenmiyorsanız reddedebilir, değişiklik isteyebilir veya karşı teklif verebilirsiniz.
                </p>
              )}
              {canAcceptWorkerCounter && (
                <p className="text-xs text-slate-500 pt-1">
                  İş alanın karşı teklifini onayladığınızda tutar hesabınızdan kilitlenir.
                </p>
              )}
              {canConfirm && (
                <p className="text-xs text-slate-500 pt-1">
                  Tamamlama ve itiraz bu yazılı sözleşmeye göre değerlendirilir.
                </p>
              )}
            </>
          ) : (
            <p className="text-sm text-slate-500 leading-relaxed">
              Henüz sözleşme yok. Önce talepleri konuşun, sonra yazılı metni yazıp teklif gönderin. Boş
              sözleşme ile iş başlamaz.
            </p>
          )}
        </div>

        <RiskIntelligencePanel
          observations={riskObservations}
          loading={riskEngineLoading}
          errorKind={riskEngineErrorKind}
          onRetry={() => {
            if (activeRoom?.id) void loadRiskEngine(activeRoom.id);
          }}
        />

        <TrustTimeline
          observations={riskObservations}
          loading={riskEngineLoading}
          errorKind={riskEngineErrorKind}
          onRetry={() => {
            if (activeRoom?.id) void loadRiskEngine(activeRoom.id);
          }}
        />

        {activeRoom?.id ? (
          <CopilotPanel
            roomId={activeRoom.id}
            observations={riskObservations}
            loading={riskEngineLoading}
            errorKind={riskEngineErrorKind}
            onRetry={() => {
              if (activeRoom?.id) void loadRiskEngine(activeRoom.id);
            }}
          />
        ) : null}

        <EscrowRoomTimelineView entries={timeline} loading={timelineLoading} />

        {error && <PanelError message={error} onGoToDeposit={onGoToDeposit} />}

        {canAccept && (
          <div className="space-y-3">
            <button
              type="button"
              onClick={handleAccept}
              disabled={acceptLoading || !(activeRoom.description || '').trim()}
              className="w-full min-h-[56px] rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-black font-bold text-lg disabled:opacity-50 cursor-pointer flex items-center justify-center gap-2"
            >
              <Lock className="h-5 w-5" />
              {acceptLoading
                ? 'Onaylanıyor…'
                : canAcceptWorkerCounter
                  ? 'Karşı teklifi onayla'
                  : 'Sözleşmeyi ve tutarı onayla'}
            </button>
          </div>
        )}

        {canNegotiateTerms && !showReject && !showRequestChanges && !showCounterOffer && (
          <div className={`${CONSOLE_CARD} p-4 space-y-2`}>
            <p className="text-sm font-semibold text-white">Teklife yanıt ver</p>
            <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
              <button
                type="button"
                onClick={() => setShowRequestChanges(true)}
                className="min-h-[44px] w-full rounded-xl border border-slate-700 py-2.5 text-sm text-slate-200 hover:border-slate-500 cursor-pointer sm:min-w-[120px] sm:flex-1"
              >
                Değişiklik iste
              </button>
              <button
                type="button"
                onClick={() => setShowCounterOffer(true)}
                className="min-h-[44px] w-full rounded-xl border border-emerald-500/40 py-2.5 text-sm text-emerald-200 hover:border-emerald-400/60 cursor-pointer sm:min-w-[120px] sm:flex-1"
              >
                Karşı teklif
              </button>
              <button
                type="button"
                onClick={() => setShowReject(true)}
                className="min-h-[44px] w-full rounded-xl border border-red-500/30 py-2.5 text-sm text-red-300 hover:border-red-400/50 cursor-pointer sm:min-w-[120px] sm:flex-1"
              >
                Reddet
              </button>
            </div>
          </div>
        )}

        {showReject && (
          <form onSubmit={handleReject} className="rounded-2xl border border-red-500/20 p-4 space-y-3">
            <p className="text-sm font-semibold text-white">Teklifi reddet</p>
            <textarea
              rows={2}
              placeholder="İsteğe bağlı gerekçe"
              value={rejectReason}
              onChange={(e) => setRejectReason(e.target.value)}
              className={`${INPUT} resize-none text-sm`}
            />
            <div className="flex gap-2">
              <button type="button" onClick={() => setShowReject(false)} className="flex-1 py-2 text-sm text-zinc-500 cursor-pointer">
                Vazgeç
              </button>
              <button type="submit" disabled={rejectLoading} className="flex-1 py-2 text-sm font-semibold text-red-300 cursor-pointer">
                {rejectLoading ? '…' : 'Reddet'}
              </button>
            </div>
          </form>
        )}

        {showRequestChanges && (
          <form onSubmit={handleRequestChanges} className="rounded-2xl border border-zinc-700 p-4 space-y-3">
            <p className="text-sm font-semibold text-white">Değişiklik talebi</p>
            <textarea
              required
              rows={3}
              minLength={10}
              placeholder="Ne değişmeli? (en az 10 karakter)"
              value={changeNote}
              onChange={(e) => setChangeNote(e.target.value)}
              className={`${INPUT} resize-none text-sm`}
            />
            <div className="flex gap-2">
              <button type="button" onClick={() => setShowRequestChanges(false)} className="flex-1 py-2 text-sm text-zinc-500 cursor-pointer">
                Vazgeç
              </button>
              <button type="submit" disabled={changesLoading || changeNote.trim().length < 10} className="flex-1 py-2 text-sm font-semibold text-white cursor-pointer">
                {changesLoading ? '…' : 'Gönder'}
              </button>
            </div>
          </form>
        )}

        {showCounterOffer && (
          <form onSubmit={handleCounterOffer} className="rounded-2xl border border-emerald-500/25 bg-emerald-950/15 p-5 space-y-3">
            <p className="text-sm font-semibold text-white">Karşı teklif</p>
            <input
              type="text"
              required
              placeholder="Kısa başlık"
              value={offerTitle}
              onChange={(e) => setOfferTitle(e.target.value)}
              className={INPUT}
            />
            <textarea
              required
              rows={5}
              minLength={CONTRACT_MIN_CHARS}
              placeholder={`Güncellenmiş sözleşme metni (en az ${CONTRACT_MIN_CHARS} karakter)`}
              value={offerContract}
              onChange={(e) => setOfferContract(e.target.value)}
              className={`${INPUT} resize-y min-h-[120px] text-sm`}
            />
            <input
              type="number"
              min={1}
              required
              placeholder={`Tutar (${CURRENCY_NAME})`}
              value={offerAmount}
              onChange={(e) =>
                setOfferAmount(e.target.value === '' ? '' : parseInt(e.target.value, 10) || '')
              }
              className={INPUT}
            />
            <div className="flex gap-2">
              <button type="button" onClick={() => setShowCounterOffer(false)} className="flex-1 py-2 text-sm text-zinc-500 cursor-pointer">
                Vazgeç
              </button>
              <button
                type="submit"
                disabled={counterLoading || offerContract.trim().length < CONTRACT_MIN_CHARS}
                className="flex-1 py-2 text-sm font-semibold text-emerald-200 cursor-pointer"
              >
                {counterLoading ? '…' : 'Karşı teklifi gönder'}
              </button>
            </div>
          </form>
        )}

        {canPropose && (
          <form onSubmit={handlePropose} className={`${CONSOLE_SURFACE} p-5 space-y-3`}>
            <p className="text-sm font-semibold text-white">Sözleşme teklifi</p>
            <p className="text-xs text-zinc-500 leading-relaxed">
              Ne isteniyor, ne teslim edilecek, süre ve kapsam — net yazın. Karşı taraf bunu onaylayınca
              para kilitlenir.
            </p>
            <input
              type="text"
              required
              placeholder="Kısa başlık (ör. Logo tasarımı)"
              value={offerTitle}
              onChange={(e) => setOfferTitle(e.target.value)}
              className={INPUT}
            />
            <textarea
              required
              rows={5}
              minLength={CONTRACT_MIN_CHARS}
              placeholder={`Sözleşme metni (en az ${CONTRACT_MIN_CHARS} karakter)\nÖrn: 3 logo taslağı, 2 revizyon, kaynak dosya teslimi, 7 gün.`}
              value={offerContract}
              onChange={(e) => setOfferContract(e.target.value)}
              className={`${INPUT} resize-y min-h-[120px] text-sm`}
            />
            <p className="text-[11px] text-zinc-600 text-right">
              {offerContract.trim().length}/{CONTRACT_MIN_CHARS}
            </p>
            <input
              type="number"
              min={1}
              required
              placeholder={`Tutar (${CURRENCY_NAME})`}
              value={offerAmount}
              onChange={(e) =>
                setOfferAmount(e.target.value === '' ? '' : parseInt(e.target.value, 10) || '')
              }
              className={INPUT}
            />
            <p className="text-xs text-zinc-500">Kullanılabilir: {formatMoney(availableBalance)}</p>
            <button
              type="submit"
              disabled={
                offerLoading ||
                offerAmount === '' ||
                offerAmount > availableBalance ||
                offerContract.trim().length < CONTRACT_MIN_CHARS
              }
              className="w-full min-h-[52px] rounded-2xl bg-white text-black font-bold disabled:opacity-50 cursor-pointer"
            >
              {offerLoading ? 'Gönderiliyor…' : 'Sözleşmeyi gönder'}
            </button>
          </form>
        )}

        {canConfirm && (
          <button
            type="button"
            onClick={handleConfirm}
            disabled={confirmLoading || alreadyConfirmed}
            className="w-full min-h-[56px] rounded-2xl bg-emerald-500 hover:bg-emerald-400 text-black font-bold text-lg disabled:opacity-50 cursor-pointer flex items-center justify-center gap-2"
          >
            <Check className="h-5 w-5" />
            {alreadyConfirmed ? 'Onayınız alındı' : confirmLoading ? '…' : 'İş bitti — onayla'}
          </button>
        )}

        {isDone && (
          <p className="text-sm text-center text-zinc-400 py-2">
            {activeRoom.status === 'completed' && 'Bu iş tamamlandı.'}
            {activeRoom.status === 'cancelled' && 'Bu iş iptal edildi.'}
            {activeRoom.status === 'resolved' && 'Şikayet sonuçlandı.'}
          </p>
        )}

        {!isDone && (
          <div className="flex gap-4 justify-center text-xs">
            {['locked', 'completion_pending'].includes(activeRoom.status) && (
              <button
                type="button"
                onClick={handleCancel}
                disabled={cancelLoading}
                className="text-zinc-500 hover:text-zinc-300 cursor-pointer"
              >
                İptal talebi
              </button>
            )}
            {['locked', 'completion_pending'].includes(activeRoom.status) && !showDispute && (
              <button
                type="button"
                onClick={() => setShowDispute(true)}
                className="text-zinc-500 hover:text-amber-300 cursor-pointer"
              >
                Sorun bildir
              </button>
            )}
          </div>
        )}

        {showDispute && (
          <form onSubmit={handleDispute} className="rounded-2xl border border-amber-500/20 p-4 space-y-3">
            <textarea
              required
              rows={3}
              placeholder="Sözleşmeye göre ne tutmadı? (kısa açıklama)"
              value={disputeReason}
              onChange={(e) => setDisputeReason(e.target.value)}
              className={`${INPUT} resize-none text-sm`}
            />
            <p className="text-[11px] text-zinc-500">
              İtiraz açan taraf %1 depozito öder. Haklı çıkana iade edilir; haksız çıkan kaybeder.
              {disputeDepositTry > 0 && (
                <span className="block mt-1 text-amber-300/90">
                  Tahmini depozito: {formatMoney(disputeDepositTry)}
                </span>
              )}
            </p>
            <div className="flex gap-2">
              <button type="button" onClick={() => setShowDispute(false)} className="flex-1 py-2 text-sm text-zinc-500 cursor-pointer">
                Vazgeç
              </button>
              <button type="submit" disabled={disputeLoading} className="flex-1 py-2 text-sm font-semibold text-amber-300 cursor-pointer">
                Gönder
              </button>
            </div>
          </form>
        )}

        <button
          type="button"
          onClick={() => {
            setShowMessages((v) => {
              const next = !v;
              if (next) scrollMessagesOnNextRender.current = true;
              return next;
            });
          }}
          className="w-full flex items-center justify-between text-sm text-zinc-500 py-2 cursor-pointer"
        >
          Mesajlar {messages.length > 0 ? `(${messages.length})` : ''}
          <ChevronDown className={`h-4 w-4 transition ${showMessages ? 'rotate-180' : ''}`} />
        </button>

        {showMessages && (
          <>
            <div className="rounded-2xl border border-zinc-800 bg-[#09090e] p-3 max-h-48 overflow-y-auto space-y-2">
              {messages.length === 0 ? (
                <p className="text-xs text-zinc-600 text-center py-4">Mesaj yok</p>
              ) : (
                messages.map((m) => (
                  <p key={m.id} className={`text-sm ${m.type === 'system' ? 'text-zinc-500 text-xs text-center' : 'text-zinc-300'}`}>
                    {m.type !== 'system' && <span className="text-zinc-600 text-xs">{m.name}: </span>}
                    {m.body}
                  </p>
                ))
              )}
              <div ref={messagesEndRef} />
            </div>
            {!isDone && (
              <form onSubmit={async (e) => {
                e.preventDefault();
                if (!messageBody.trim() || !activeRoomId) return;
                setSendLoading(true);
                try {
                  const { room, messages: msgs } = await sendEscrowRoomMessage(activeRoomId, messageBody.trim());
                  roomPollKeyRef.current = roomPollKey(room);
                  messagesPollKeyRef.current = messagesPollKey(msgs);
                  setActiveRoom(room);
                  setMessages(msgs);
                  setMessageBody('');
                  scrollMessagesOnNextRender.current = true;
                } catch (err) {
                  setError(err instanceof Error ? err.message : 'Gönderilemedi.');
                } finally {
                  setSendLoading(false);
                }
              }} className="flex gap-2">
                <input
                  value={messageBody}
                  onChange={(e) => setMessageBody(e.target.value)}
                  placeholder="Mesaj…"
                  className={`${INPUT} flex-1 text-sm min-h-[44px]`}
                />
                <button type="submit" disabled={sendLoading || !messageBody.trim()} className="shrink-0 px-4 rounded-xl bg-zinc-800 text-white cursor-pointer disabled:opacity-50">
                  <Send className="h-4 w-4" />
                </button>
              </form>
            )}
          </>
        )}

        {loading && <p className="text-xs text-zinc-600 text-center">Güncelleniyor…</p>}
      </div>
    );
  }

  const renderVerificationBlocked = () => (
    <div className="rounded-2xl border border-amber-500/25 bg-amber-500/[0.06] p-5 space-y-3 text-center">
      <p className="text-sm font-semibold text-amber-100">Yeni anlaşma başlatmak için doğrulama gerekli</p>
      <p className="text-xs text-zinc-400 leading-relaxed">
        E-posta ve kimlik (KYC) doğrulamalarını profilinden tamamladıktan sonra sözleşme
        oluşturabilirsin.
      </p>
      <button
        type="button"
        onClick={() => onBlockedCreateContract?.()}
        className="min-h-[44px] px-5 rounded-xl bg-white text-black text-sm font-bold cursor-pointer"
      >
        Doğrulamaları tamamla
      </button>
    </div>
  );

  return (
    <div className="mx-auto w-full min-w-0 max-w-lg space-y-4 escrow-stable-scroll">
      {variant === 'my-deals' && (
        <div className="flex items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-bold text-white">Anlaşmalarım</h2>
            <p className="text-xs text-zinc-500">Açık ve tamamlanan anlaşmalarınız</p>
          </div>
          {!showNewConnect && (
            <button
              type="button"
              onClick={tryOpenNewConnect}
              className="shrink-0 min-h-[44px] px-4 rounded-xl bg-white text-black text-sm font-bold flex items-center gap-1.5 cursor-pointer"
            >
              <Plus className="h-4 w-4" /> Yeni
            </button>
          )}
        </div>
      )}

      {variant === 'start' && (
        <div className="text-center space-y-1 pb-1">
          <h2 className="text-lg font-bold text-white">Yeni Anlaşma</h2>
          <p className="text-xs text-zinc-500">Karşı tarafın ZN-ID'si ile emanet bağlantısı</p>
        </div>
      )}

      {error && <PanelError message={error} onGoToDeposit={onGoToDeposit} />}

      {(variant === 'start' || showNewConnect) &&
        (canCreateContract ? renderConnectForm() : renderVerificationBlocked())}

      {variant === 'my-deals' && showNewConnect && (
        <button
          type="button"
          onClick={() => setShowNewConnect(false)}
          className="text-xs text-zinc-500 hover:text-zinc-300 cursor-pointer w-full text-center"
        >
          Listeye dön
        </button>
      )}

      {variant === 'start' && rooms.length > 0 && (
        <div className="space-y-2">
          <p className="text-xs text-zinc-500">Devam eden işler</p>
          {renderRoomList()}
        </div>
      )}

      {variant === 'my-deals' && !showNewConnect && renderRoomList()}
    </div>
  );
}
