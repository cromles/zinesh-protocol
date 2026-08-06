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
  escrowRoomNextAction,
  escrowRoomStatusLabel,
  fetchEscrowRoomDetail,
  fetchEscrowRooms,
  fileEscrowDispute,
  proposeEscrowTerms,
  requestEscrowRoomCancel,
  sendEscrowRoomMessage,
  type EscrowRoom,
  type EscrowRoomMessage,
  type EscrowRoomRole,
} from '../lib/escrowRoomApi';
import { fetchWalletState } from '../lib/walletApi';
import { getSessionToken } from '../lib/auth';
import { CONTRACT_MIN_CHARS, ESCROW_PARTY } from '../lib/plainLanguage';
import EslesmeSinyalCenter from './EslesmeSinyalCenter';
import { displayMemberTicket } from '../lib/memberTicket';
import { isInsufficientBalanceMessage } from '../lib/insufficientBalance';
import type { EslesmeSinyal } from '../lib/eslesmeSinyalApi';

const INPUT =
  'w-full min-h-[48px] bg-zinc-900 border border-zinc-800 rounded-2xl px-4 py-3 text-base text-zinc-100 placeholder-zinc-600 focus:outline-none focus:border-emerald-500/40';

const ROOM_POLL_MS = 12_000;

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
  ].join('|');
}

function messagesPollKey(msgs: EscrowRoomMessage[]): string {
  if (msgs.length === 0) return '';
  const last = msgs[msgs.length - 1];
  return `${msgs.length}:${last.id}:${last.createdAt}`;
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
  }, []);

  const openRoom = useCallback(
    async (roomId: string) => {
      setActiveRoomId(roomId);
      setError('');
      setLoading(true);
      setShowMessages(false);
      roomPollKeyRef.current = '';
      messagesPollKeyRef.current = '';
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
    [setActiveRoomId],
  );

  const closeRoom = () => {
    setActiveRoomId(null);
    setActiveRoom(null);
    setMessages([]);
    setError('');
    setShowDispute(false);
  };

  useEffect(() => {
    loadRooms();
    const tick = () => {
      if (document.visibilityState === 'hidden') return;
      loadRooms();
    };
    const t = window.setInterval(tick, ROOM_POLL_MS);
    return () => window.clearInterval(t);
  }, [loadRooms]);

  useEffect(() => {
    if (!activeRoomId) {
      setActiveRoom(null);
      setMessages([]);
      roomPollKeyRef.current = '';
      messagesPollKeyRef.current = '';
      return;
    }
    void refreshRoomDetail(activeRoomId, true);
    const tick = () => {
      if (document.visibilityState === 'hidden') return;
      void refreshRoomDetail(activeRoomId, true);
    };
    const t = window.setInterval(tick, ROOM_POLL_MS);
    return () => window.clearInterval(t);
  }, [activeRoomId, refreshRoomDetail]);

  useEffect(() => {
    if (!showMessages || !scrollMessagesOnNextRender.current) return;
    scrollMessagesOnNextRender.current = false;
    messagesEndRef.current?.scrollIntoView({ behavior: 'auto', block: 'nearest' });
  }, [messages, showMessages]);

  const handleConnect = async (e: React.FormEvent) => {
    e.preventDefault();
    setConnectLoading(true);
    setError('');
    try {
      const room = await connectEscrowRoom(peerTicket.trim(), connectRole);
      await loadRooms();
      setShowNewConnect(false);
      setPeerTicket('');
      await openRoom(room.id);
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Bağlantı kurulamadı.');
    } finally {
      setConnectLoading(false);
    }
  };

  const handlePropose = async (e: React.FormEvent) => {
    e.preventDefault();
    const contract = offerContract.trim();
    if (!activeRoomId || offerAmount === '' || offerAmount < 1 || !offerTitle.trim()) return;
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

  const renderConnectForm = () => (
    <form onSubmit={handleConnect} className="rounded-2xl border border-zinc-800 bg-[#09090e] p-5 space-y-4">
      <div>
        <p className="text-sm font-semibold text-white">Karşı tarafın üye numarası</p>
        <p className="text-xs text-zinc-500 mt-0.5">Karşı tarafın üye numarasını girin (ör. 22595)</p>
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
              : 'border-zinc-700 text-zinc-400'
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
              : 'border-zinc-700 text-zinc-400'
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
      <p className="text-[11px] text-zinc-500 text-center">
        Numaranız: <span className="font-mono text-zinc-300">{displayMemberTicket(myTicketNumber)}</span>
      </p>
    </form>
  );

  const renderRoomList = () => (
    <div className="space-y-3">
      {rooms.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-zinc-800 p-8 text-center">
          <p className="text-sm text-zinc-400">Henüz emanet yok.</p>
          <p className="text-xs text-zinc-600 mt-1">Karşı tarafın numarasıyla yeni iş başlatın.</p>
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
                  : 'border-zinc-800 bg-[#09090e] hover:border-zinc-700'
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
      <div className="max-w-lg mx-auto space-y-4 escrow-stable-scroll">
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
    const canPropose = isEmployer && ['negotiating', 'terms_pending'].includes(activeRoom.status);
    const canAccept = isWorker && activeRoom.status === 'terms_pending' && activeRoom.agreedAmountTry > 0;
    const canConfirm = ['locked', 'completion_pending'].includes(activeRoom.status);
    const alreadyConfirmed =
      (isEmployer && activeRoom.employerConfirmedComplete) ||
      (isWorker && activeRoom.workerConfirmedComplete);
    const isDone = ['completed', 'cancelled', 'resolved'].includes(activeRoom.status);

    return (
      <div className="max-w-lg mx-auto space-y-4 escrow-stable-scroll">
        <button
          type="button"
          onClick={closeRoom}
          className="text-sm text-zinc-400 hover:text-white flex items-center gap-1.5 cursor-pointer py-1"
        >
          <ArrowLeft className="h-4 w-4" /> Listeye dön
        </button>

        <div className="rounded-2xl border border-zinc-800 bg-[#09090e] p-5 space-y-2">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="text-lg font-bold text-white">{activeRoom.title || 'İş görüşmesi'}</p>
              <p className="text-sm text-zinc-400">
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

        <div className="rounded-2xl border border-purple-500/25 bg-purple-500/[0.06] p-5 space-y-2">
          <p className="font-mono text-[10px] uppercase tracking-wider text-purple-300/90 font-bold">
            Yazılı sözleşme
          </p>
          {(activeRoom.description || '').trim() ? (
            <>
              <p className="text-sm text-zinc-200 whitespace-pre-wrap leading-relaxed">
                {activeRoom.description}
              </p>
              {canAccept && (
                <p className="text-xs text-zinc-500 pt-1">
                  Onayladığınızda bu metin ve tutar kilitlenir. Beğenmiyorsanız mesajlaşarak düzeltme isteyin.
                </p>
              )}
              {canConfirm && (
                <p className="text-xs text-zinc-500 pt-1">
                  Tamamlama ve itiraz bu yazılı sözleşmeye göre değerlendirilir.
                </p>
              )}
            </>
          ) : (
            <p className="text-sm text-zinc-500 leading-relaxed">
              Henüz sözleşme yok. Önce talepleri konuşun, sonra yazılı metni yazıp teklif gönderin. Boş
              sözleşme ile iş başlamaz.
            </p>
          )}
        </div>

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
              {acceptLoading ? 'Onaylanıyor…' : 'Sözleşmeyi ve tutarı onayla'}
            </button>
          </div>
        )}

        {canPropose && (
          <form onSubmit={handlePropose} className="rounded-2xl border border-zinc-800 bg-[#09090e] p-5 space-y-3">
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
              İtiraz, yukarıdaki yazılı sözleşme metnine göre incelenir.
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

  return (
    <div className="max-w-lg mx-auto space-y-4 escrow-stable-scroll">
      {variant === 'my-deals' && (
        <div className="flex items-center justify-between gap-3">
          <div>
            <h2 className="text-lg font-bold text-white">Emanetlerim</h2>
            <p className="text-xs text-zinc-500">Açık işleriniz — istediğinize tıklayın</p>
          </div>
          {!showNewConnect && (
            <button
              type="button"
              onClick={() => setShowNewConnect(true)}
              className="shrink-0 min-h-[44px] px-4 rounded-xl bg-white text-black text-sm font-bold flex items-center gap-1.5 cursor-pointer"
            >
              <Plus className="h-4 w-4" /> Yeni
            </button>
          )}
        </div>
      )}

      {variant === 'start' && (
        <div className="text-center space-y-1 pb-1">
          <h2 className="text-lg font-bold text-white">Yeni iş başlat</h2>
          <p className="text-xs text-zinc-500">Karşı tarafın üye numarası yeterli</p>
        </div>
      )}

      {error && <PanelError message={error} onGoToDeposit={onGoToDeposit} />}

      {(variant === 'start' || showNewConnect) && renderConnectForm()}

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
