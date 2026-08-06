import React, { useState, useEffect, useCallback } from 'react';
import WalletCenter from './WalletCenter';
import EmailVerificationBanner from './EmailVerificationBanner';
import NotificationBar from './NotificationBar';
import KycVerificationCard from './KycVerificationCard';
import FounderPlatformPanel from './FounderPlatformPanel';
import FounderSystemHealthPanel from './FounderSystemHealthPanel';
import FounderBalancePanel from './FounderBalancePanel';
import EscrowRoomPanel from './EscrowRoomPanel';
import { PANEL_GUIDES, NAV_CARDS, MOBILE_QUICK_LINKS, type ConsolePaneId } from './consolePanelCopy';
import { showsPlatformWallet } from '../lib/consolePaneModes';
import {
  fetchWalletState,
  fetchDepositAddresses,
  verifyDeposit,
  requestWithdraw,
  addConnectedWallet,
  applyWalletToState,
  type DepositNetwork,
  type TlHavaleInfo,
} from '../lib/walletApi';
import { confirmEscrowComplete, requestEscrowRoomCancel } from '../lib/escrowRoomApi';
import { formatMoney } from '../lib/currencyFormat';
import { getSession, refreshSession, getSessionToken } from '../lib/auth';
import { FALLBACK_DEPOSIT_ADDRESSES, mergeDepositAddresses } from '../lib/depositAddresses';

const NAV_CARD_IDLE =
  'bg-[rgba(255,255,255,0.015)] border border-amber-500/20 hover:border-amber-500/55 hover:shadow-[0_0_24px_rgba(245,158,11,0.08)] text-zinc-100 transition-all duration-300';
const NAV_CARD_ACTIVE =
  'bg-[rgba(255,255,255,0.025)] border border-amber-500/50 shadow-[0_0_28px_rgba(245,158,11,0.1)] text-white';

interface AlphaConsoleProps {
  onBrowseLanding: () => void;
  onLogout: () => void;
  onSessionExpired?: () => void;
  onUserUpdate?: (user: import('../lib/firebase').UserProfile) => void;
  registeredUser?: {
    name: string;
    email: string;
    role: 'web3' | 'real' | 'dual';
    ticketNumber: string;
    trustScore?: number;
    usdtBalance?: number;
    walletAddress?: string;
    emailVerified?: boolean;
    sessionToken?: string;
    uid?: string;
    isFounder?: boolean;
    foundingMember?: boolean;
    foundingMemberNumber?: number;
    kycStatus?: string;
    referralCode?: string;
  };
}

type ActivePane = ConsolePaneId;

export default function AlphaConsole({
  onBrowseLanding,
  onLogout,
  registeredUser,
  onUserUpdate,
}: AlphaConsoleProps) {
  const [activePane, setActivePane] = useState<ActivePane>('dashboard');
  const [isFounder, setIsFounder] = useState(() => Boolean(registeredUser?.isFounder));

  const emailVerified = registeredUser?.emailVerified ?? false;
  const kycStatus = (registeredUser?.kycStatus as string) ?? 'none';
  const kycApproved = kycStatus === 'approved';
  const sessionToken = registeredUser?.sessionToken ?? getSessionToken();
  const [flashToast, setFlashToast] = useState<string | null>(null);

  useEffect(() => {
    try {
      const toast = sessionStorage.getItem('zinesh_flash_toast') || sessionStorage.getItem('zinesh_verify_toast');
      if (toast) {
        setFlashToast(toast);
        sessionStorage.removeItem('zinesh_flash_toast');
        sessionStorage.removeItem('zinesh_verify_toast');
      }
    } catch {
      /* ignore */
    }
  }, []);

  const pageGuide = PANEL_GUIDES[activePane];
  const platformWalletPane = showsPlatformWallet(activePane);
  const username = registeredUser?.name || 'Üye';

  const [usdtBalance, setUsdtBalance] = useState(0);
  const [availableUsdt, setAvailableUsdt] = useState(0);
  const [depositAddresses, setDepositAddresses] = useState<
    Record<string, { label: string; address: string; asset: string }>
  >(mergeDepositAddresses(FALLBACK_DEPOSIT_ADDRESSES));
  const [minWithdrawUsdt, setMinWithdrawUsdt] = useState(5);
  const [minDepositUsdt, setMinDepositUsdt] = useState(10);
  const [depositsEnabled, setDepositsEnabled] = useState(true);
  const [treasuryUsdtAvailable, setTreasuryUsdtAvailable] = useState<
    Partial<Record<DepositNetwork, number | null>>
  >({});

  const [connectedWallets, setConnectedWallets] = useState<
    Array<{ id: string; name: string; network: string; address: string }>
  >([]);
  const [newWalletName, setNewWalletName] = useState('');
  const [newWalletNetwork, setNewWalletNetwork] = useState('tron');
  const [newWalletAddress, setNewWalletAddress] = useState('');
  const [isAddingWallet, setIsAddingWallet] = useState(false);
  const [depositNetwork, setDepositNetwork] = useState<DepositNetwork>('tron');
  const [depositTxHash, setDepositTxHash] = useState('');
  const [isDepositing, setIsDepositing] = useState(false);
  const [withdrawNetwork, setWithdrawNetwork] = useState<DepositNetwork>('tron');
  const [selectedWithdrawWallet, setSelectedWithdrawWallet] = useState('');
  const [withdrawAmount, setWithdrawAmount] = useState<number | ''>('');
  const [customWithdrawAddress, setCustomWithdrawAddress] = useState('');
  const [useCustomWithdrawAddress, setUseCustomWithdrawAddress] = useState(false);
  const [isWithdrawing, setIsWithdrawing] = useState(false);
  const [walletTab, setWalletTab] = useState<'deposit' | 'withdraw' | 'wallets'>('deposit');
  const [walletTxLogs, setWalletTxLogs] = useState<import('../lib/walletApi').WalletTransaction[]>([]);
  const [tlHavale, setTlHavale] = useState<TlHavaleInfo | undefined>(undefined);
  const [escrowRoomId, setEscrowRoomId] = useState<string | null>(null);

  const scrollToConsoleTarget = useCallback((targetId: string) => {
    window.requestAnimationFrame(() => {
      const el = document.getElementById(targetId);
      if (el) el.scrollIntoView({ behavior: 'auto', block: 'start' });
    });
  }, []);

  const goToPane = useCallback(
    (pane: ActivePane, scrollTarget?: string) => {
      if (pane !== 'sozlesmelerim' && pane !== 'hizmet-al') {
        setEscrowRoomId(null);
      }
      setActivePane(pane);
      if (scrollTarget) {
        scrollToConsoleTarget(scrollTarget);
      }
    },
    [scrollToConsoleTarget],
  );

  const goToDeposit = useCallback(() => {
    setWalletTab('deposit');
    goToPane('dashboard', 'wallet-center');
  }, [goToPane]);

  const syncWallet = useCallback(
    (wallet: Awaited<ReturnType<typeof fetchWalletState>>) => {
      applyWalletToState(wallet, {
        setUsdtBalance,
        setWalletTxLogs,
        setConnectedWallets,
        setAvailableUsdt,
      });
      setDepositAddresses(wallet.depositAddresses);
      setMinWithdrawUsdt(wallet.minWithdrawUsdt);
      setMinDepositUsdt(wallet.minDepositUsdt ?? 10);
      setDepositsEnabled(wallet.depositsEnabled !== false);
      setTreasuryUsdtAvailable(wallet.treasuryUsdtAvailable ?? {});
      if (wallet.isFounder !== undefined) setIsFounder(Boolean(wallet.isFounder));
      setTlHavale(wallet.tlHavale);
      if (wallet.connectedWallets.length > 0) {
        setSelectedWithdrawWallet((prev) => prev || wallet.connectedWallets[0].id);
      }
      // wallet.php yanıtındaki user (ticketNumber vb.) localStorage'a yazılır;
      // React parent state'i de güncelle ki mobil/web aynı Üye ID'yi görsün.
      const session = getSession();
      if (session?.ticketNumber) onUserUpdate?.(session);
    },
    [onUserUpdate],
  );

  const loadFounderAndWallet = useCallback(() => {
    const tokenAtStart = getSessionToken();
    if (!tokenAtStart) return;

    refreshSession({ timeoutMs: 25000 })
      .then((user) => {
        setIsFounder(Boolean(user.isFounder));
        onUserUpdate?.(user);
        return user;
      })
      .catch((err) => {
        // Arka plan yenilemede oturumu hemen öldürme — geçici 401/kilit yarışları olabiliyor.
        console.warn('session refresh failed', err);
        return null;
      })
      .then(() => fetchWalletState())
      .then((wallet) => {
        syncWallet(wallet);
        if (wallet.isFounder) setIsFounder(true);
      })
      .catch(() => {});
  }, [onUserUpdate, syncWallet]);

  useEffect(() => {
    loadFounderAndWallet();
  }, [loadFounderAndWallet]);

  useEffect(() => {
    fetchDepositAddresses().then(setDepositAddresses).catch(() => {});
  }, []);

  useEffect(() => {
    if (registeredUser) {
      // Session cache can lag behind live wallet; only seed if still empty
      if (typeof registeredUser.usdtBalance === 'number') {
        setUsdtBalance((prev) => (prev > 0 ? prev : registeredUser.usdtBalance!));
        setAvailableUsdt((prev) => (prev > 0 ? prev : registeredUser.usdtBalance!));
      }
    }
  }, [registeredUser]);

  useEffect(() => {
    setIsFounder(Boolean(registeredUser?.isFounder));
  }, [registeredUser?.isFounder]);

  const handleAddWallet = (e: React.FormEvent) => {
    e.preventDefault();
    if (!newWalletName.trim() || !newWalletAddress.trim()) {
      alert('Lütfen cüzdan adı ve adresini doldurun.');
      return;
    }
    addConnectedWallet(newWalletName.trim(), newWalletNetwork, newWalletAddress.trim())
      .then((wallet) => {
        syncWallet(wallet);
        setNewWalletName('');
        setNewWalletAddress('');
        setIsAddingWallet(false);
        alert('Cüzdan kaydedildi.');
      })
      .catch((err: Error) => alert(err.message));
  };

  const handleDepositExternal = (e: React.FormEvent) => {
    e.preventDefault();
    if (!depositTxHash.trim()) {
      alert('İşlem numarası (TXID) gerekli.');
      return;
    }
    setIsDepositing(true);
    verifyDeposit(depositNetwork, depositTxHash.trim())
      .then((wallet) => {
        syncWallet(wallet);
        setDepositTxHash('');
        alert('Yatırman doğrulandı ve bakiyene eklendi.');
      })
      .catch((err: Error) => alert(err.message))
      .finally(() => setIsDepositing(false));
  };

  const handleWithdrawExternal = (e: React.FormEvent) => {
    e.preventDefault();
    const amount = Number(withdrawAmount);
    if (!withdrawAmount || isNaN(amount) || amount <= 0) {
      alert('Geçerli bir tutar girin.');
      return;
    }

    let targetAddr = '';
    if (useCustomWithdrawAddress) {
      if (!customWithdrawAddress.trim()) {
        alert('Çekim adresi gerekli.');
        return;
      }
      targetAddr = customWithdrawAddress.trim();
    } else {
      const selectedW = connectedWallets.find((w) => w.id === selectedWithdrawWallet);
      if (!selectedW) {
        alert('Kayıtlı cüzdan seçin veya özel adres girin.');
        return;
      }
      if (selectedW.network !== withdrawNetwork) {
        alert(`Seçili cüzdan ${selectedW.network.toUpperCase()} ağında. Çekim ağı: ${withdrawNetwork.toUpperCase()}.`);
        return;
      }
      targetAddr = selectedW.address;
    }

    setIsWithdrawing(true);
    requestWithdraw(withdrawNetwork, targetAddr, amount)
      .then((result) => {
        syncWallet(result.wallet);
        setWithdrawAmount('');
        setCustomWithdrawAddress('');
        alert(result.message);
      })
      .catch((err: Error) => alert(err.message))
      .finally(() => setIsWithdrawing(false));
  };

  return (
    <div id="alpha-portal-root" className="min-h-screen bg-[#06060a] text-zinc-100 flex flex-col font-sans relative overflow-x-hidden">
      <div className="absolute top-[10%] left-[10%] h-[300px] w-[300px] rounded-full bg-indigo-500/[0.02] blur-[120px] pointer-events-none" />
      <div className="absolute top-[60%] right-[10%] h-[400px] w-[400px] rounded-full bg-purple-500/[0.03] blur-[150px] pointer-events-none" />

      <NotificationBar
        sessionToken={sessionToken ?? undefined}
        userName={username}
        userTicket={registeredUser?.ticketNumber ?? ''}
        onHome={onBrowseLanding}
        onLogout={onLogout}
        onOpenProfile={() => goToPane('dashboard')}
        onOpenEslesme={(eslesmeId) => {
          setEscrowRoomId(eslesmeId);
          goToPane('sozlesmelerim');
        }}
        onApproveComplete={async (eslesmeId) => {
          await confirmEscrowComplete(eslesmeId);
          setEscrowRoomId(eslesmeId);
          goToPane('sozlesmelerim');
        }}
        onApproveCancel={async (eslesmeId) => {
          await requestEscrowRoomCancel(eslesmeId);
          setEscrowRoomId(eslesmeId);
          goToPane('sozlesmelerim');
        }}
      />

      <div className="notification-bar-spacer shrink-0" aria-hidden />

      {flashToast && (
        <div className="relative z-40 max-w-7xl mx-auto safe-pad-x pt-4">
          <div className="flex items-start justify-between gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
            <span className="min-w-0 break-words">{flashToast}</span>
            <button
              type="button"
              onClick={() => setFlashToast(null)}
              className="shrink-0 touch-target inline-flex items-center justify-center text-emerald-200/80 hover:text-white text-xs font-mono cursor-pointer"
            >
              Kapat
            </button>
          </div>
        </div>
      )}

      <nav
        aria-label="Mobil hızlı erişim"
        className="lg:hidden sticky top-[calc(56px+env(safe-area-inset-top,0px))] z-40 border-b border-zinc-900/70 bg-[#06060a]/95 max-w-7xl mx-auto safe-pad-x py-2"
      >
        <div className="mobile-quick-nav flex gap-2 overflow-x-auto pb-0.5">
          {MOBILE_QUICK_LINKS.map((link) => {
            const isWalletLink = link.scrollTarget === 'wallet-center';
            const isActive =
              (isWalletLink && activePane === 'dashboard') ||
              (!isWalletLink && activePane === link.pane);
            return (
              <button
                key={link.label}
                type="button"
                onClick={() => goToPane(link.pane, link.scrollTarget)}
                className={`shrink-0 min-h-[44px] px-3.5 py-2 rounded-xl border text-[11px] font-semibold whitespace-nowrap transition cursor-pointer ${
                  isActive
                    ? 'bg-amber-500/15 border-amber-500/45 text-amber-100'
                    : 'bg-zinc-900/70 border-zinc-700/80 text-zinc-200'
                }`}
              >
                <span className="mr-1" aria-hidden>
                  {link.emoji}
                </span>
                {link.label}
              </button>
            );
          })}
        </div>
      </nav>

      <div className="flex-1 max-w-7xl w-full mx-auto safe-pad-x py-4 sm:py-6 flex flex-col safe-pad-b min-w-0">
        {activePane !== 'hizmet-al' && activePane !== 'sozlesmelerim' && (
          <div className="mb-4 border-b border-zinc-900 pb-4 w-full min-w-0 space-y-4">
            <div className="flex flex-col md:flex-row md:items-end justify-between gap-4">
              <div className="space-y-1.5 text-left">
                <span className="text-[10px] font-mono font-semibold text-purple-400 tracking-wider">
                  {activePane === 'dashboard' ? 'ANA SAYFAM' : pageGuide.title.toUpperCase()}
                </span>
                <h1 className="font-display text-2xl font-black text-white leading-tight">
                  {activePane === 'dashboard' ? (
                    <>
                      Hoş Geldin,{' '}
                      <span className="bg-gradient-to-r from-purple-300 via-indigo-200 to-indigo-300 bg-clip-text text-transparent">
                        {username}
                      </span>
                    </>
                  ) : (
                    pageGuide.title
                  )}
                </h1>
                <p className="text-xs text-zinc-400 max-w-xl">{pageGuide.subtitle}</p>
              </div>
              {activePane !== 'dashboard' && platformWalletPane ? (
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px] text-zinc-500 md:text-right">
                  <span>
                    Bakiye:{' '}
                    <span className="text-zinc-300 font-medium tabular-nums">{formatMoney(usdtBalance)}</span>
                  </span>
                </div>
              ) : null}
            </div>
          </div>
        )}

        {!emailVerified && registeredUser?.email && (
          <EmailVerificationBanner
            email={registeredUser.email}
            uid={registeredUser.uid}
            sessionToken={sessionToken}
            onResent={(user) => {
              if (user) onUserUpdate?.(user);
              else refreshSession().then(onUserUpdate).catch(() => {});
            }}
          />
        )}

        {activePane === 'dashboard' && (
          <div id="console-dashboard" className="console-scroll-target space-y-4">
            {isFounder && (
              <div id="founder-system-health" className="console-scroll-target space-y-4">
                <FounderBalancePanel sessionToken={sessionToken} />
                <FounderSystemHealthPanel sessionToken={sessionToken} />
              </div>
            )}

            {isFounder && <FounderPlatformPanel />}

            {!isFounder && emailVerified && !kycApproved && (
              <KycVerificationCard
                fullNameDefault={registeredUser?.name}
                emailVerified={emailVerified}
                kycStatus={kycStatus}
                onSuccess={(user) => onUserUpdate?.(user)}
              />
            )}

            {!isFounder && (
              <div className="grid grid-cols-2 gap-3">
                {NAV_CARDS.map((card) => (
                  <button
                    key={card.id}
                    type="button"
                    onClick={() => goToPane(card.id)}
                    className={`rounded-2xl p-5 min-h-[100px] text-left border transition cursor-pointer flex flex-col justify-between ${
                      activePane === card.id ? NAV_CARD_ACTIVE : NAV_CARD_IDLE
                    }`}
                  >
                    <span className="text-2xl" aria-hidden>{card.emoji}</span>
                    <div>
                      <h3 className="text-base font-bold text-white">{card.label}</h3>
                      <p className="text-[11px] text-zinc-500 mt-0.5">{card.short}</p>
                    </div>
                  </button>
                ))}
              </div>
            )}

            {/* Cüzdan: web + mobil aynı bileşen (kurucu dahil) */}
            <div id="wallet-center" className="console-scroll-target">
              <WalletCenter
                usdtBalance={usdtBalance}
                availableUsdt={availableUsdt}
                depositAddresses={depositAddresses}
                walletTab={walletTab}
                setWalletTab={setWalletTab}
                depositNetwork={depositNetwork}
                setDepositNetwork={setDepositNetwork}
                depositTxHash={depositTxHash}
                setDepositTxHash={setDepositTxHash}
                isDepositing={isDepositing}
                handleDepositExternal={handleDepositExternal}
                withdrawAmount={withdrawAmount}
                setWithdrawAmount={setWithdrawAmount}
                withdrawNetwork={withdrawNetwork}
                setWithdrawNetwork={setWithdrawNetwork}
                selectedWithdrawWallet={selectedWithdrawWallet}
                setSelectedWithdrawWallet={setSelectedWithdrawWallet}
                useCustomWithdrawAddress={useCustomWithdrawAddress}
                setUseCustomWithdrawAddress={setUseCustomWithdrawAddress}
                customWithdrawAddress={customWithdrawAddress}
                setCustomWithdrawAddress={setCustomWithdrawAddress}
                isWithdrawing={isWithdrawing}
                handleWithdrawExternal={handleWithdrawExternal}
                connectedWallets={connectedWallets}
                isAddingWallet={isAddingWallet}
                setIsAddingWallet={setIsAddingWallet}
                newWalletName={newWalletName}
                setNewWalletName={setNewWalletName}
                newWalletNetwork={newWalletNetwork}
                setNewWalletNetwork={setNewWalletNetwork}
                newWalletAddress={newWalletAddress}
                setNewWalletAddress={setNewWalletAddress}
                handleAddWallet={handleAddWallet}
                walletTxLogs={walletTxLogs}
                minWithdrawUsdt={minWithdrawUsdt}
                minDepositUsdt={minDepositUsdt}
                depositsEnabled={depositsEnabled}
                treasuryUsdtAvailable={treasuryUsdtAvailable}
                tlHavale={tlHavale}
                onHavaleReported={(wallet) => syncWallet(wallet)}
                ticketNumber={registeredUser?.ticketNumber}
              />
            </div>
          </div>
        )}

        {activePane === 'hizmet-al' && (
          <div id="console-pane-hizmet-al" className="console-scroll-target">
            <EscrowRoomPanel
              variant="start"
              myTicketNumber={registeredUser?.ticketNumber ?? ''}
              availableBalance={availableUsdt}
              activeRoomId={escrowRoomId}
              onActiveRoomIdChange={setEscrowRoomId}
              onWalletRefresh={(wallet) => {
                applyWalletToState(wallet, {
                  setUsdtBalance,
                  setWalletTxLogs,
                  setConnectedWallets,
                  setAvailableUsdt,
                });
              }}
              onGoToDeposit={goToDeposit}
            />
          </div>
        )}

        {activePane === 'sozlesmelerim' && (
          <div id="console-pane-sozlesmelerim" className="console-scroll-target space-y-4 text-left">
            <EscrowRoomPanel
              variant="my-deals"
              myTicketNumber={registeredUser?.ticketNumber ?? ''}
              availableBalance={availableUsdt}
              activeRoomId={escrowRoomId}
              onActiveRoomIdChange={setEscrowRoomId}
              onWalletRefresh={(wallet) => {
                applyWalletToState(wallet, {
                  setUsdtBalance,
                  setWalletTxLogs,
                  setConnectedWallets,
                  setAvailableUsdt,
                });
              }}
              onGoToDeposit={goToDeposit}
            />
          </div>
        )}
      </div>

      <footer className="border-t border-zinc-900 bg-[#040407]/80 backdrop-blur-sm py-4 mt-auto">
        <div className="max-w-7xl mx-auto safe-pad-x text-center font-mono text-[10px] text-zinc-600">
          Zinesh · 2026
        </div>
      </footer>
    </div>
  );
}
