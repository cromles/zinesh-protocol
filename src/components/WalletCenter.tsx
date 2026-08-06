import React, { useEffect, useState } from 'react';
import { PLAIN } from '../lib/plainLanguage';
import { displayMemberTicket } from '../lib/memberTicket';
import { formatMoney } from '../lib/currencyFormat';
import { createTlDeposit, fetchPaymentStatus, fetchHavaleInfo, type PaymentStatus } from '../lib/paymentApi';
import { reportHavaleDeposit, type TlHavaleInfo } from '../lib/walletApi';
import { Copy, ArrowDownLeft, ArrowUpRight, ChevronDown, HelpCircle, CreditCard, Check, UserRound } from 'lucide-react';
import type { DepositNetwork } from '../lib/walletApi';
import { toast } from '../lib/toast';

type WalletTab = 'deposit' | 'withdraw' | 'wallets';

interface WalletLog {
  id: string;
  type: 'deposit' | 'withdrawal' | 'swap' | 'escrow_send' | 'escrow_receive' | 'escrow_lock' | 'escrow_reconcile_debit';
  amount: string;
  asset: string;
  txHash: string;
  date: string;
  createdAt?: string;
  status: 'completed' | 'processing' | 'failed';
  label?: string;
}

interface WalletCenterProps {
  usdtBalance: number;
  availableUsdt?: number;
  walletTab: WalletTab;
  setWalletTab: (tab: WalletTab) => void;
  handleWithdrawExternal: (e: React.FormEvent) => void;
  walletTxLogs: WalletLog[];
  tlHavale?: import('../lib/walletApi').TlHavaleInfo;
  onHavaleReported?: (wallet: import('../lib/walletApi').WalletState) => void;
  ticketNumber?: string;
  // Deprecated crypto-era props — kept optional so AlphaConsole still typechecks.
  // The USER wallet UI is TL-only; these are unused here.
  depositAddresses?: Record<string, { label: string; address: string; asset: string }>;
  depositNetwork?: DepositNetwork;
  setDepositNetwork?: (n: DepositNetwork) => void;
  depositTxHash?: string;
  setDepositTxHash?: (v: string) => void;
  isDepositing?: boolean;
  handleDepositExternal?: (e: React.FormEvent) => void;
  withdrawAmount?: number | '';
  setWithdrawAmount?: (v: number | '') => void;
  withdrawNetwork?: DepositNetwork;
  setWithdrawNetwork?: (n: DepositNetwork) => void;
  selectedWithdrawWallet?: string;
  setSelectedWithdrawWallet?: (v: string) => void;
  useCustomWithdrawAddress?: boolean;
  setUseCustomWithdrawAddress?: (v: boolean) => void;
  customWithdrawAddress?: string;
  setCustomWithdrawAddress?: (v: string) => void;
  isWithdrawing?: boolean;
  connectedWallets?: Array<{ id: string; name: string; network: string; address: string }>;
  isAddingWallet?: boolean;
  setIsAddingWallet?: (v: boolean) => void;
  newWalletName?: string;
  setNewWalletName?: (v: string) => void;
  newWalletNetwork?: string;
  setNewWalletNetwork?: (v: string) => void;
  newWalletAddress?: string;
  setNewWalletAddress?: (v: string) => void;
  handleAddWallet?: (e: React.FormEvent) => void;
  minWithdrawUsdt?: number;
  minDepositUsdt?: number;
  treasuryUsdtAvailable?: Partial<Record<DepositNetwork, number | null>>;
  depositsEnabled?: boolean;
  depositsDisabledMessage?: string;
}

const INPUT =
  'w-full bg-zinc-950/80 border border-zinc-800 rounded-2xl px-4 py-3.5 text-base sm:text-sm text-white placeholder:text-zinc-600';
const BTN_PRIMARY =
  'w-full min-h-[52px] py-3.5 text-base sm:text-sm font-bold rounded-2xl cursor-pointer disabled:opacity-50 active:scale-[0.98] transition-transform';

function copyText(text: string) {
  navigator.clipboard
    .writeText(text)
    .then(() => toast.success('Kopyalandı.'))
    .catch(() => toast.error('Kopyalanamadı. Panoya erişim reddedildi.'));
}

function formatTxDate(log: WalletLog): string {
  if (log.date) return log.date;
  if (log.createdAt) {
    return new Date(log.createdAt).toLocaleString('tr-TR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  }
  return '—';
}

function txTypeLabel(log: WalletLog): string {
  if (log.label) return log.label;
  switch (log.type) {
    case 'deposit':
      return 'Yatırma';
    case 'withdrawal':
      return 'Çekme';
    case 'escrow_send':
      return 'Emanet gönderimi';
    case 'escrow_receive':
      return 'Emanet alımı';
    case 'escrow_lock':
      return 'Emanet kilidi';
    case 'escrow_reconcile_debit':
      return 'Emanet düzeltmesi';
    default:
      return log.type;
  }
}

function txIsCredit(type: WalletLog['type']): boolean {
  return type === 'deposit' || type === 'escrow_receive';
}

export default function WalletCenter(props: WalletCenterProps) {
  const [showWhy, setShowWhy] = useState(false);
  const [depositAmountTry, setDepositAmountTry] = useState<number | ''>(100);
  const [havaleAmountTry, setHavaleAmountTry] = useState<number | ''>(100);
  const [havaleNote, setHavaleNote] = useState('');
  const [isHavaleReporting, setIsHavaleReporting] = useState(false);
  const [paymentStatus, setPaymentStatus] = useState<PaymentStatus | null>(null);
  const [localHavale, setLocalHavale] = useState<TlHavaleInfo | undefined>(undefined);
  const [isCardPaying, setIsCardPaying] = useState(false);
  const [memberIdCopied, setMemberIdCopied] = useState(false);

  const mode = props.walletTab === 'wallets' ? 'withdraw' : props.walletTab;
  const available = props.availableUsdt ?? props.usdtBalance;
  const txLogs = props.walletTxLogs.filter((log) => log.type !== 'swap');

  useEffect(() => {
    let cancelled = false;
    fetchPaymentStatus()
      .then((status) => {
        if (!cancelled) setPaymentStatus(status);
      })
      .catch(() => {
        if (!cancelled) setPaymentStatus(null);
      });
    fetchHavaleInfo()
      .then((info) => {
        if (!cancelled && info) setLocalHavale(info);
      })
      .catch(() => {});
    return () => {
      cancelled = true;
    };
  }, []);

  const havaleFromPayment =
    paymentStatus?.havale?.iban && paymentStatus.havale.enabled !== false
      ? {
          enabled: Boolean(paymentStatus.havale.enabled),
          iban: paymentStatus.havale.iban,
          accountHolder: paymentStatus.havale.accountHolder ?? '',
          bankName: paymentStatus.havale.bankName ?? '',
          minDepositTry: paymentStatus.havale.minDepositTry ?? 50,
          reference: '',
        }
      : undefined;
  const havale = props.tlHavale ?? localHavale ?? havaleFromPayment;
  const havaleMin = havale?.minDepositTry ?? paymentStatus?.minDepositTry ?? 50;
  const havaleReady = Boolean(havale?.enabled && havale.iban);

  const handleCardDeposit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (depositAmountTry === '' || depositAmountTry < havaleMin) {
      alert(`Minimum ${havaleMin} TL girin.`);
      return;
    }
    setIsCardPaying(true);
    try {
      const result = await createTlDeposit(depositAmountTry);
      if (!result.ok || !result.paymentPageUrl) {
        alert(result.message || 'Ödeme başlatılamadı.');
        return;
      }
      window.location.href = result.paymentPageUrl;
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Ödeme başlatılamadı.');
    } finally {
      setIsCardPaying(false);
    }
  };

  const handleHavaleReport = async (e: React.FormEvent) => {
    e.preventDefault();
    if (havaleAmountTry === '' || havaleAmountTry < havaleMin) {
      alert(`Minimum ${havaleMin} TL girin.`);
      return;
    }
    setIsHavaleReporting(true);
    try {
      const result = await reportHavaleDeposit(havaleAmountTry, havaleNote);
      if (result.wallet && props.onHavaleReported) {
        props.onHavaleReported(result.wallet);
      }
      alert(result.message);
      if (result.autoApproved) {
        setHavaleNote('');
      }
    } catch (err: unknown) {
      alert(err instanceof Error ? err.message : 'Havale bildirilemedi.');
    } finally {
      setIsHavaleReporting(false);
    }
  };

  const formatIban = (iban: string) =>
    iban.replace(/\s+/g, '').replace(/(.{4})/g, '$1 ').trim();

  useEffect(() => {
    if (props.walletTab === 'wallets') {
      props.setWalletTab('withdraw');
    }
  }, [props.walletTab]);

  const setMode = (tab: 'deposit' | 'withdraw') => {
    props.setWalletTab(tab);
  };

  const copyMemberId = async () => {
    if (!props.ticketNumber) return;
    try {
      await navigator.clipboard.writeText(displayMemberTicket(props.ticketNumber));
      setMemberIdCopied(true);
      window.setTimeout(() => setMemberIdCopied(false), 2000);
    } catch {
      copyText(displayMemberTicket(props.ticketNumber));
    }
  };

  return (
    <div className="rounded-3xl border border-zinc-800/80 bg-[#09090e] overflow-hidden text-left w-full shadow-[0_8px_40px_rgba(0,0,0,0.35)]">
      {/* Bakiye */}
      <div className="relative px-5 sm:px-6 pt-5 sm:pt-6 pb-4 bg-gradient-to-br from-emerald-500/[0.12] via-purple-500/[0.06] to-transparent border-b border-white/[0.06]">
        <div className="flex items-start justify-between gap-4">
          <div>
            <p className="text-[11px] font-semibold text-emerald-300/90 mb-1">{PLAIN.siteWallet}</p>
            <p className="text-[2.5rem] sm:text-5xl font-black text-white tabular-nums leading-none tracking-tight">
              {formatMoney(available)}
            </p>
            <p className="text-sm text-zinc-400 mt-2">
              {props.usdtBalance > available + 0.01
                ? `${formatMoney(props.usdtBalance - available)} emanet/kilitli · `
                : ''}
              Kullanılabilir TL bakiye
            </p>
          </div>
          <div className="h-12 w-12 rounded-2xl bg-white/[0.06] border border-white/10 flex items-center justify-center shrink-0">
            <CreditCard className="h-6 w-6 text-emerald-300" />
          </div>
        </div>
      </div>

      {props.ticketNumber ? (
        <div className="mx-4 sm:mx-5 mt-4 mb-3 rounded-2xl border border-purple-500/30 bg-purple-500/[0.08] p-4">
          <div className="flex items-start justify-between gap-3">
            <div className="min-w-0">
              <p className="flex items-center gap-1.5 text-[11px] font-bold text-purple-300/90 uppercase tracking-wider">
                <UserRound className="h-3.5 w-3.5 shrink-0" aria-hidden />
                Üye ID
              </p>
              <p className="mt-1.5 font-mono text-[1.05rem] sm:text-xl font-bold text-white tracking-[0.06em] break-all">
                {displayMemberTicket(props.ticketNumber)}
              </p>
              <p className="mt-1.5 text-[12px] text-zinc-400 leading-snug">
                İlk eşleşmede karşı tarafa bu numarayı gönderin (WhatsApp vb.).
              </p>
            </div>
            <button
              type="button"
              onClick={() => void copyMemberId()}
              className="shrink-0 inline-flex items-center justify-center gap-1.5 rounded-xl border border-white/15 bg-white/5 px-3.5 py-2.5 text-xs font-semibold text-white hover:bg-white/10 transition cursor-pointer min-h-[44px] min-w-[88px]"
              aria-label="Üye ID kopyala"
            >
              {memberIdCopied ? (
                <Check className="h-4 w-4 text-emerald-400" aria-hidden />
              ) : (
                <Copy className="h-4 w-4" aria-hidden />
              )}
              {memberIdCopied ? 'Kopyalandı' : 'Kopyala'}
            </button>
          </div>
        </div>
      ) : (
        <div className="mx-4 sm:mx-5 mt-4 mb-3 rounded-2xl border border-amber-500/25 bg-amber-500/[0.06] px-4 py-3 text-[12px] text-amber-200/90">
          Üye ID yükleniyor… Sayfayı yenilersen veya çıkış/giriş yaparsan görünür.
        </div>
      )}

      {/* Son hareketler — mobilde yatır/çek formunun üstünde (web ile aynı içerik) */}
      <div className="mx-4 sm:mx-5 mb-3 rounded-2xl border border-zinc-800 bg-zinc-950/40 p-3.5">
        <span className="text-xs font-semibold text-zinc-400">Son hareketler</span>
        {txLogs.length === 0 ? (
          <p className="mt-2 text-[12px] text-zinc-500 leading-snug">
            Henüz kayıtlı hareket yok. Havale, emanet kilidi ve ödemeler burada görünür.
          </p>
        ) : (
          <div className="mt-2 space-y-1.5 max-h-52 overflow-y-auto overscroll-contain">
            {txLogs.slice(0, 12).map((log) => (
              <div
                key={log.id}
                className="flex justify-between items-center gap-3 text-sm py-2.5 px-3 rounded-xl bg-[#09090e] border border-zinc-900/80"
              >
                <div className="min-w-0">
                  <span className="text-zinc-300 flex items-center gap-2">
                    {txIsCredit(log.type) ? (
                      <ArrowDownLeft className="h-4 w-4 text-emerald-400 shrink-0" />
                    ) : (
                      <ArrowUpRight className="h-4 w-4 text-rose-400 shrink-0" />
                    )}
                    {txTypeLabel(log)}
                  </span>
                  <span className="text-[11px] text-zinc-500 mt-0.5 block tabular-nums">
                    {formatTxDate(log)}
                  </span>
                </div>
                <span className="font-semibold text-white tabular-nums shrink-0">
                  {txIsCredit(log.type) ? '+' : '−'}
                  {Number(log.amount).toLocaleString('tr-TR')} {log.asset}
                </span>
              </div>
            ))}
          </div>
        )}
      </div>

      {/* Neden kasa? */}
      <button
        type="button"
        onClick={() => setShowWhy((v) => !v)}
        className="w-full flex items-center justify-between gap-2 px-5 py-3 text-left border-b border-white/[0.04] hover:bg-white/[0.02] transition cursor-pointer"
      >
        <span className="flex items-center gap-2 text-[12px] text-zinc-400">
          <HelpCircle className="h-3.5 w-3.5 text-sky-400 shrink-0" />
          Zinesh kasası nasıl çalışır?
        </span>
        <ChevronDown className={`h-4 w-4 text-zinc-500 transition-transform ${showWhy ? 'rotate-180' : ''}`} />
      </button>
      {showWhy && (
        <div className="px-5 py-3 border-b border-white/[0.04] bg-sky-500/[0.04] text-[12px] text-zinc-300 leading-relaxed">
          <strong className="text-white">Alıcı</strong> tutarı buraya yatırır; para satıcıya doğrudan gitmez.{' '}
          <strong className="text-white">Satıcı</strong> teslim eder; alıcı onaylayınca ödeme aktarılır. İtirazda kasa
          kilitlenir, hakem heyeti karar verir. Taraflar birbirine IBAN vermez.
        </div>
      )}

      {/* Yatır / Çek */}
      <div className="p-4 grid grid-cols-2 gap-2">
        <button
          type="button"
          onClick={() => setMode('deposit')}
          className={`min-h-[56px] rounded-2xl border font-bold text-sm flex flex-col items-center justify-center gap-0.5 transition cursor-pointer active:scale-[0.98] ${
            mode === 'deposit'
              ? 'border-emerald-500/50 bg-emerald-500/15 text-emerald-100 shadow-[0_0_24px_rgba(16,185,129,0.12)]'
              : 'border-zinc-800 bg-zinc-950/50 text-zinc-400 hover:text-white'
          }`}
        >
          <span className="text-lg" aria-hidden>
            📥
          </span>
          Para yatır
        </button>
        <button
          type="button"
          onClick={() => setMode('withdraw')}
          className={`min-h-[56px] rounded-2xl border font-bold text-sm flex flex-col items-center justify-center gap-0.5 transition cursor-pointer active:scale-[0.98] ${
            mode === 'withdraw'
              ? 'border-purple-500/50 bg-purple-500/15 text-purple-100 shadow-[0_0_24px_rgba(168,85,247,0.12)]'
              : 'border-zinc-800 bg-zinc-950/50 text-zinc-400 hover:text-white'
          }`}
        >
          <span className="text-lg" aria-hidden>
            📤
          </span>
          Para çek
        </button>
      </div>

      <div className="px-4 sm:px-5 pb-5 space-y-4">
        {mode === 'deposit' && (
          <div className="space-y-4">
            {havaleReady ? (
              <form onSubmit={handleHavaleReport} className="space-y-4">
                <div className="rounded-2xl border border-emerald-500/25 bg-emerald-500/10 p-4 space-y-3">
                  <p className="text-[11px] font-bold text-emerald-300/90 uppercase tracking-wider">
                    Havale / EFT ile yatır
                  </p>
                  <ol className="space-y-2 text-[13px] text-zinc-300">
                    <li>
                      <span className="text-emerald-400 font-bold">1.</span> Aşağıdaki IBAN&apos;a transfer yap
                    </li>
                    <li>
                      <span className="text-emerald-400 font-bold">2.</span> Açıklamaya{' '}
                      {havale.reference ? (
                        <strong className="text-white font-mono">{havale.reference}</strong>
                      ) : (
                        <span className="text-zinc-400">referans yükleniyor…</span>
                      )}{' '}
                      yaz
                    </li>
                    <li>
                      <span className="text-emerald-400 font-bold">3.</span> Tutarı gir ve &quot;Havale yaptım&quot; de
                    </li>
                  </ol>
                </div>

                <div className="p-4 rounded-2xl bg-zinc-950 border border-zinc-800 space-y-3">
                  {havale.bankName && (
                    <p className="text-xs text-zinc-400">
                      Banka: <span className="text-zinc-200">{havale.bankName}</span>
                    </p>
                  )}
                  {havale.accountHolder && (
                    <p className="text-xs text-zinc-400">
                      Alıcı: <span className="text-zinc-200">{havale.accountHolder}</span>
                    </p>
                  )}
                  <span className="text-xs text-zinc-400 block">IBAN</span>
                  <div className="flex items-start gap-2">
                    <span className="text-sm font-mono text-emerald-400 break-all leading-relaxed flex-1">
                      {formatIban(havale.iban)}
                    </span>
                    <button
                      type="button"
                      onClick={() => copyText(havale.iban.replace(/\s+/g, ''))}
                      className="shrink-0 p-3 rounded-xl bg-emerald-500/15 border border-emerald-500/30 hover:bg-emerald-500/25"
                      aria-label="IBAN kopyala"
                    >
                      <Copy className="h-4 w-4 text-emerald-300" />
                    </button>
                  </div>
                  <p className="text-[11px] text-amber-200/90 rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2">
                    Referans (açıklama):{' '}
                    {havale.reference ? (
                      <strong className="font-mono text-white">{havale.reference}</strong>
                    ) : (
                      <span className="text-zinc-400">yükleniyor…</span>
                    )}
                  </p>
                </div>

                <label className="text-sm text-zinc-300 font-medium block">Gönderdiğin tutar (TL)</label>
                <input
                  type="number"
                  min={havaleMin}
                  step={1}
                  className={INPUT}
                  value={havaleAmountTry}
                  onChange={(e) => setHavaleAmountTry(e.target.value === '' ? '' : Number(e.target.value))}
                  placeholder="ör. 500"
                />
                <label className="text-sm text-zinc-300 font-medium block">Dekont notu (isteğe bağlı)</label>
                <input
                  type="text"
                  className={INPUT}
                  value={havaleNote}
                  onChange={(e) => setHavaleNote(e.target.value)}
                  placeholder="Banka dekont saati vb."
                />
                <button
                  type="submit"
                  disabled={isHavaleReporting || !havale.reference}
                  className={`${BTN_PRIMARY} bg-emerald-600 hover:bg-emerald-500 text-white`}
                >
                  {isHavaleReporting ? 'Gönderiliyor…' : 'Havale yaptım — bakiyeye işle'}
                </button>
                <p className="text-[11px] text-zinc-500">
                  Min. {havaleMin} TL · Kurucu hesabında test için anında onaylanır; diğer üyelerde admin onayı gerekir.
                </p>
              </form>
            ) : (
              <div className="rounded-2xl border border-amber-500/30 bg-amber-500/10 p-4 text-[13px] text-amber-100/90">
                Havale IBAN henüz yapılandırılmadı. Sunucuda <span className="font-mono">config.local.php</span>{' '}
                içine IBAN eklenmeli.
              </div>
            )}

            {paymentStatus?.paymentEnabled && (
              <form onSubmit={handleCardDeposit} className="space-y-4 pt-2 border-t border-zinc-800">
                <p className="text-[11px] font-bold text-zinc-500 uppercase tracking-wider">veya kart ile</p>
                <label className="text-sm text-zinc-300 font-medium block">Tutar (TL)</label>
                <input
                  type="number"
                  min={paymentStatus?.minDepositTry ?? 50}
                  max={paymentStatus?.maxDepositTry ?? 50000}
                  step={1}
                  className={INPUT}
                  value={depositAmountTry}
                  onChange={(e) => setDepositAmountTry(e.target.value === '' ? '' : Number(e.target.value))}
                  placeholder="ör. 500"
                />
                <button
                  type="submit"
                  disabled={isCardPaying}
                  className={`${BTN_PRIMARY} bg-zinc-800 hover:bg-zinc-700 text-white border border-zinc-700`}
                >
                  {isCardPaying ? 'Yönlendiriliyor…' : 'Güvenli ödeme sayfasına git'}
                </button>
              </form>
            )}

            {!paymentStatus?.paymentEnabled && !havale?.enabled && (
              <p className="text-[12px] text-amber-200/90 rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2">
                Kart ödemesi henüz aktif değil — iyzico anahtarları sunucuda tanımlanınca açılacak.
              </p>
            )}
          </div>
        )}

        {mode === 'withdraw' && (
          <div className="rounded-2xl border border-purple-500/25 bg-purple-500/10 p-4 text-[13px] text-purple-100/90">
            IBAN ile çekim Faz 2&apos;de aktif olacak. Şimdilik bakiyeniz site cüzdanında güvenle tutulur.
          </div>
        )}
      </div>
    </div>
  );
}
