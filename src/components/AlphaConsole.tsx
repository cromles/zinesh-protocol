import React, { useState, useEffect, useCallback } from 'react';
import WalletCenter from './WalletCenter';
import EmailVerificationBanner from './EmailVerificationBanner';
import NotificationBar from './NotificationBar';
import FounderPlatformPanel from './FounderPlatformPanel';
import FounderSystemHealthPanel from './FounderSystemHealthPanel';
import FounderBalancePanel from './FounderBalancePanel';
import EscrowRoomPanel from './EscrowRoomPanel';
import ProfileSettingsPanel from './ProfileSettingsPanel';
import ConsoleDashboardOverview from './ConsoleDashboardOverview';
import ConsoleSidebar from './ConsoleSidebar';
import EslesmeSinyalCenter from './EslesmeSinyalCenter';
import ContractVerificationModal from './ContractVerificationModal';
import { PANEL_GUIDES, type ConsolePaneId } from './consolePanelCopy';
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
import { getSession, refreshSession, getSessionToken } from '../lib/auth';
import { FALLBACK_DEPOSIT_ADDRESSES, mergeDepositAddresses } from '../lib/depositAddresses';
import {
  VERIFICATION_PROFILE_ANCHORS,
  canCreateContract,
  contractVerificationFromUser,
  getMissingContractVerifications,
  type VerificationTier,
} from '../lib/contractVerification';

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
    phoneVerified?: boolean;
    phoneMasked?: string;
    kycVerified?: boolean;
    referralCode?: string;
    createdAt?: string;
    jobHistoryPublic?: boolean;
    googleLinked?: boolean;
    totpEnabled?: boolean;
    signupRewardAmount?: number;
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
  const [verificationModalOpen, setVerificationModalOpen] = useState(false);
  const [verificationMissing, setVerificationMissing] = useState<VerificationTier[]>([]);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [notifPanelOpen, setNotifPanelOpen] = useState(false);

  const contractReady = canCreateContract(registeredUser);

  const scrollToConsoleTarget = useCallback((targetId: string) => {
    window.requestAnimationFrame(() => {
      const el = document.getElementById(targetId);
      if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  }, []);

  const goToHome = useCallback(() => {
    onBrowseLanding();
  }, [onBrowseLanding]);

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
    goToPane('cuzdan', 'wallet-center');
  }, [goToPane]);

  const openNotifications = useCallback(() => {
    setNotifPanelOpen(true);
  }, []);

  const handleSupport = useCallback(() => {
    window.open('mailto:zinesh.protocol@gmail.com?subject=Zinesh%20Destek', '_blank', 'noopener,noreferrer');
  }, []);

  const openEscrowRoom = useCallback(
    (roomId: string) => {
      setEscrowRoomId(roomId);
      goToPane('sozlesmelerim');
    },
    [goToPane],
  );

  const openVerificationModal = useCallback(() => {
    const missing = getMissingContractVerifications(contractVerificationFromUser(registeredUser));
    setVerificationMissing(missing);
    setVerificationModalOpen(missing.length > 0);
  }, [registeredUser]);

  const handleGoToVerification = useCallback(
    (tier: VerificationTier) => {
      setVerificationModalOpen(false);
      goToPane('bilgilerim', VERIFICATION_PROFILE_ANCHORS[tier]);
    },
    [goToPane],
  );

  const navigateToPane = useCallback(
    (pane: ActivePane) => {
      if (pane === 'hizmet-al' && !contractReady) {
        openVerificationModal();
        return;
      }
      goToPane(pane);
    },
    [contractReady, goToPane, openVerificationModal],
  );

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
        const escrow =
          typeof registeredUser.escrowBalance === 'number' ? registeredUser.escrowBalance : 0;
        const available = Math.max(0, registeredUser.usdtBalance - escrow);
        setUsdtBalance((prev) => (prev > 0 ? prev : registeredUser.usdtBalance!));
        setAvailableUsdt((prev) => (prev > 0 ? prev : available));
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

  const walletCenterProps = {
    usdtBalance,
    availableUsdt,
    depositAddresses,
    walletTab,
    setWalletTab,
    depositNetwork,
    setDepositNetwork,
    depositTxHash,
    setDepositTxHash,
    isDepositing,
    handleDepositExternal,
    withdrawAmount,
    setWithdrawAmount,
    withdrawNetwork,
    setWithdrawNetwork,
    selectedWithdrawWallet,
    setSelectedWithdrawWallet,
    useCustomWithdrawAddress,
    setUseCustomWithdrawAddress,
    customWithdrawAddress,
    setCustomWithdrawAddress,
    isWithdrawing,
    handleWithdrawExternal,
    connectedWallets,
    isAddingWallet,
    setIsAddingWallet,
    newWalletName,
    setNewWalletName,
    newWalletNetwork,
    setNewWalletNetwork,
    newWalletAddress,
    setNewWalletAddress,
    handleAddWallet,
    walletTxLogs,
    minWithdrawUsdt,
    minDepositUsdt,
    depositsEnabled,
    treasuryUsdtAvailable,
    tlHavale,
    onHavaleReported: (wallet: Awaited<ReturnType<typeof fetchWalletState>>) => syncWallet(wallet),
    ticketNumber: registeredUser?.ticketNumber,
  } as const;

  return (
    <div id="alpha-portal-root" className="relative flex min-h-screen overflow-x-hidden bg-slate-950 font-sans text-slate-100">
      <div className="pointer-events-none absolute left-[10%] top-[10%] h-[300px] w-[300px] rounded-full bg-emerald-500/[0.03] blur-[120px]" />
      <div className="pointer-events-none absolute right-[10%] top-[60%] h-[400px] w-[400px] rounded-full bg-teal-500/[0.04] blur-[150px]" />

      <ConsoleSidebar
        activePane={activePane}
        userName={username}
        ticketNumber={registeredUser?.ticketNumber}
        availableBalance={availableUsdt}
        emailVerified={registeredUser?.emailVerified}
        kycVerified={registeredUser?.kycVerified}
        mobileOpen={sidebarOpen}
        onCloseMobile={() => setSidebarOpen(false)}
        onNavigate={(pane) => {
          navigateToPane(pane);
        }}
        onDeposit={goToDeposit}
        onLogout={onLogout}
        onSupport={handleSupport}
        onHome={goToHome}
      />

      <NotificationBar
        sessionToken={sessionToken ?? undefined}
        userName={username}
        userTicket={registeredUser?.ticketNumber ?? ''}
        userEmail={registeredUser?.email ?? ''}
        availableBalance={availableUsdt}
        onHome={goToHome}
        onLogout={onLogout}
        onOpenProfile={() => goToPane('bilgilerim')}
        onOpenDashboard={() => goToPane('dashboard')}
        consoleMobile
        onOpenMenu={() => setSidebarOpen(true)}
        panelOpen={notifPanelOpen}
        onPanelOpenChange={setNotifPanelOpen}
        onOpenEslesme={(eslesmeId) => {
          setEscrowRoomId(eslesmeId);
          goToPane('sozlesmelerim');
          setNotifPanelOpen(false);
        }}
        onApproveComplete={async (eslesmeId) => {
          await confirmEscrowComplete(eslesmeId);
          setEscrowRoomId(eslesmeId);
          goToPane('sozlesmelerim');
          setNotifPanelOpen(false);
        }}
        onApproveCancel={async (eslesmeId) => {
          await requestEscrowRoomCancel(eslesmeId);
          setEscrowRoomId(eslesmeId);
          goToPane('sozlesmelerim');
          setNotifPanelOpen(false);
        }}
      />

      <div className="flex min-w-0 flex-1 flex-col lg:pl-[280px]">
        <div className="notification-bar-spacer shrink-0 lg:hidden" aria-hidden />

      {flashToast && (
        <div className="relative z-40 mx-auto w-full max-w-7xl safe-pad-x pt-4">
          <div className="flex items-start justify-between gap-3 rounded-xl border border-emerald-500/25 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-100">
            <span className="min-w-0 break-words">{flashToast}</span>
            <button
              type="button"
              onClick={() => setFlashToast(null)}
              aria-label="Bildirimi kapat"
              className="shrink-0 touch-target inline-flex items-center justify-center text-emerald-200/80 hover:text-white text-xs font-mono cursor-pointer"
            >
              Kapat
            </button>
          </div>
        </div>
      )}

      <ContractVerificationModal
        open={verificationModalOpen}
        missing={verificationMissing}
        onClose={() => setVerificationModalOpen(false)}
        onGoToVerification={handleGoToVerification}
      />

      <main id="console-main" aria-label="Kullanıcı konsolu" className="mx-auto flex w-full max-w-7xl min-w-0 flex-1 flex-col safe-pad-x py-3 sm:py-6 safe-pad-b">
        {activePane !== 'dashboard' && (
          <div className="mb-4 hidden w-full min-w-0 border-b border-slate-800 pb-4 lg:block">
            <div className="space-y-1.5 text-left">
              <span className="text-[10px] font-mono font-semibold tracking-wider text-emerald-400">
                {pageGuide.title}
              </span>
              <h1 className="font-display text-2xl font-black leading-tight text-white">{pageGuide.title}</h1>
              <p className="max-w-xl text-xs text-slate-400">{pageGuide.subtitle}</p>
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
          <div className="console-scroll-target space-y-4 sm:space-y-5">
            {isFounder && (
              <div id="founder-system-health" className="console-scroll-target space-y-4">
                <FounderBalancePanel sessionToken={sessionToken} />
                <FounderSystemHealthPanel sessionToken={sessionToken} />
              </div>
            )}

            {isFounder && <FounderPlatformPanel />}

            {!isFounder && (
              <ConsoleDashboardOverview
                userName={username}
                availableBalance={availableUsdt}
                onNavigate={navigateToPane}
                onOpenRoom={openEscrowRoom}
                onOpenNotifications={openNotifications}
              />
            )}
          </div>
        )}

        {activePane === 'cuzdan' && (
          <div id="wallet-center" className="console-scroll-target">
            <WalletCenter {...walletCenterProps} />
          </div>
        )}

        {activePane === 'bildirimler' && (
          <div id="console-pane-bildirimler" className="console-scroll-target">
            <EslesmeSinyalCenter
              sessionToken={sessionToken}
              onEylem={(s) => {
                setEscrowRoomId(s.eslesme_id);
                goToPane('sozlesmelerim');
              }}
            />
          </div>
        )}

        {activePane === 'bilgilerim' && (
          <ProfileSettingsPanel
            user={{
              name: registeredUser?.name,
              email: registeredUser?.email,
              ticketNumber: registeredUser?.ticketNumber,
              trustScore: registeredUser?.trustScore,
              referralCode: registeredUser?.referralCode,
              kycStatus: registeredUser?.kycStatus,
              emailVerified: registeredUser?.emailVerified,
              kycVerified: registeredUser?.kycVerified,
              role: registeredUser?.role,
              isFounder,
              createdAt: registeredUser?.createdAt,
              uid: registeredUser?.uid,
              jobHistoryPublic: registeredUser?.jobHistoryPublic,
              googleLinked: registeredUser?.googleLinked,
              totpEnabled: registeredUser?.totpEnabled,
              signupRewardAmount: registeredUser?.signupRewardAmount,
            }}
            sessionToken={sessionToken}
            onUserUpdate={(user) => onUserUpdate?.(user)}
            onPasswordChanged={onLogout}
            onGoToDashboard={() => goToPane('dashboard')}
            onStartNewAgreement={() => navigateToPane('hizmet-al')}
          />
        )}

        {activePane === 'hizmet-al' && (
          <div id="console-pane-hizmet-al" className="console-scroll-target">
            <EscrowRoomPanel
              variant="start"
              myTicketNumber={registeredUser?.ticketNumber ?? ''}
              availableBalance={availableUsdt}
              activeRoomId={escrowRoomId}
              onActiveRoomIdChange={setEscrowRoomId}
              canCreateContract={contractReady}
              onBlockedCreateContract={openVerificationModal}
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
              canCreateContract={contractReady}
              onBlockedCreateContract={openVerificationModal}
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
      </main>

      <footer className="mt-auto border-t border-slate-800 bg-slate-950/80 py-4 backdrop-blur-sm lg:pl-[280px]">
        <div className="mx-auto max-w-7xl safe-pad-x text-center font-mono text-[10px] text-slate-500">
          Zinesh · 2026
        </div>
      </footer>
      </div>
    </div>
  );
}
