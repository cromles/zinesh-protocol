import { DISPUTE_OUTCOMES_NOTE, PLAIN_ESCROW_NOTE } from '../lib/plainLanguage';

export type ConsolePaneId =
  | 'dashboard'
  | 'bilgilerim'
  | 'hizmet-al'
  | 'sozlesmelerim'
  | 'cuzdan'
  | 'bildirimler';

export interface PanelGuide {
  title: string;
  subtitle: string;
  purpose: string;
  steps: string[];
  protocolNote: string;
}

export const PANEL_GUIDES: Record<ConsolePaneId, PanelGuide> = {
  dashboard: {
    title: 'Genel Bakış',
    subtitle: 'Güvenli anlaşmalar, şeffaf süreçler',
    purpose: 'Önce yazılı sözleşme, sonra kasa. Emanet başlatıp karşı tarafla şartlarda anlaşın; onay sonrası tutar güvenli kasaya yatırılır.',
    steps: [
      'Emanet başlat: karşı tarafla sözleşme şartlarında anlaşın.',
      'Onay sonrası alıcı tutarı kasaya yatırır.',
      'Teslim sonrası Emanetlerim’den onaylayın veya itiraz edin.',
    ],
    protocolNote: 'Para kasada; sözleşme şartları karşılanana kadar kimseye verilmez.',
  },
  bilgilerim: {
    title: 'Bilgilerim',
    subtitle: 'Hesap ve üyelik bilgilerin',
    purpose: 'Ad, e-posta, üye numarası ve doğrulama durumun tek ekranda.',
    steps: [
      'Üye numaranı emanet işlemlerinde karşı tarafa ilet.',
      'E-posta ve kimlik doğrulamasını tamamla.',
      'Güven puanın tamamlanan işlemlerle artar.',
    ],
    protocolNote: 'Kişisel bilgiler yalnızca güvenlik ve kimlik doğrulama için kullanılır.',
  },
  'hizmet-al': {
    title: 'Yeni Anlaşma',
    subtitle: 'Karşı tarafın ZN-ID ile emanet bağlantısı',
    purpose:
      'Karşı tarafın üye numarasını girin, rolünüzü seçin (alıcı/satıcı) ve yazılı sözleşme şartlarında anlaşın. Onay sonrası tutar kasaya kilitlenir.',
    steps: [
      'Üye numarasını girin, rolünüzü seçin.',
      'Sözleşme şartlarında anlaşın — karşı taraf onaylasın.',
      'Alıcı tutarı kasaya yatırır; teslim → kontrol → onay veya itiraz.',
    ],
    protocolNote: `${DISPUTE_OUTCOMES_NOTE} Tamamlanan işlerden %5 protokol komisyonu kesilir.`,
  },
  sozlesmelerim: {
    title: 'Anlaşmalarım',
    subtitle: 'Devam eden ve tamamlanan anlaşmalar',
    purpose: 'Yazılı sözleşmeye bağlı tüm emanet işlemleriniz. Onay bekleyen, itirazlı ve tamamlanan kayıtlar tek ekranda.',
    steps: [
      'İşi seçin.',
      'Sözleşme şartlarına göre onay veya itiraz verin.',
      'İtirazda hakem süreci yazılı sözleşmeyi esas alır.',
    ],
    protocolNote: PLAIN_ESCROW_NOTE,
  },
  cuzdan: {
    title: 'Cüzdan',
    subtitle: 'Bakiye yükleme ve işlem geçmişi',
    purpose: 'Kullanılabilir bakiyenizi görün, TL yükleyin ve hareketleri takip edin.',
    steps: [
      'Bakiye yükle sekmesinden TL yatırın.',
      'Kullanılabilir bakiyeniz anlaşma kilitleme için kullanılır.',
      'İşlem geçmişinden hareketleri inceleyin.',
    ],
    protocolNote: 'Bakiye yalnızca onaylı emanet süreçlerinde kilitlenir.',
  },
  bildirimler: {
    title: 'Bildirimler',
    subtitle: 'Anlaşma ve süreç bildirimleri',
    purpose: 'Emanet odası güncellemeleri ve onay bekleyen işlemler.',
    steps: [
      'Okunmamış bildirimleri kontrol edin.',
      'İlgili anlaşmaya gidip işlemi tamamlayın.',
    ],
    protocolNote: 'Bildirimler yalnızca hesabınıza bağlı anlaşmalar içindir.',
  },
};

/** Gizlenen panel yok — sade 3 sekmelik konsol. */
export const ESCROW_HIDDEN_PANES: readonly ConsolePaneId[] = [];

export type QuickNavIconId = 'new-contract' | 'past-contracts' | 'wallet';

export const NAV_CARDS: Array<{
  id: ConsolePaneId;
  icon: QuickNavIconId;
  label: string;
  short: string;
  color: 'purple' | 'orange' | 'sky' | 'emerald' | 'amber' | 'indigo';
}> = [
  { id: 'hizmet-al', icon: 'new-contract', label: 'Yeni Anlaşma', short: 'ZN-ID ile bağlan', color: 'purple' },
  { id: 'sozlesmelerim', icon: 'past-contracts', label: 'Anlaşmalarım', short: 'Onay ve itiraz', color: 'sky' },
];

export const HEADER_WALLET_LINK = {
  pane: 'cuzdan' as const,
  label: 'Cüzdan',
  icon: 'wallet' as const,
  scrollTarget: 'wallet-center',
};

export function isPaneVisible(pane: ConsolePaneId): boolean {
  return !ESCROW_HIDDEN_PANES.includes(pane);
}

export function consolePaneAnchorId(pane: ConsolePaneId): string {
  if (pane === 'dashboard') return 'console-dashboard';
  if (pane === 'bilgilerim') return 'console-pane-bilgilerim';
  if (pane === 'cuzdan') return 'wallet-center';
  return `console-pane-${pane}`;
}
