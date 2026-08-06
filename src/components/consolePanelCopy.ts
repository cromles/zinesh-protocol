import { DISPUTE_OUTCOMES_NOTE, PLAIN_ESCROW_NOTE } from '../lib/plainLanguage';

export type ConsolePaneId = 'dashboard' | 'hizmet-al' | 'sozlesmelerim';

export interface PanelGuide {
  title: string;
  subtitle: string;
  purpose: string;
  steps: string[];
  protocolNote: string;
}

export const PANEL_GUIDES: Record<ConsolePaneId, PanelGuide> = {
  dashboard: {
    title: 'Ana Sayfam',
    subtitle: 'Kasa ve hızlı işlemler',
    purpose: 'Önce yazılı sözleşme, sonra kasa. Emanet başlatıp karşı tarafla şartlarda anlaşın; onay sonrası tutar güvenli kasaya yatırılır.',
    steps: [
      'Emanet başlat: karşı tarafla sözleşme şartlarında anlaşın.',
      'Onay sonrası alıcı tutarı kasaya yatırır.',
      'Teslim sonrası Emanetlerim’den onaylayın veya itiraz edin.',
    ],
    protocolNote: 'Para kasada; sözleşme şartları karşılanana kadar kimseye verilmez.',
  },
  'hizmet-al': {
    title: 'Emanet başlat',
    subtitle: 'Önce sözleşme, sonra kasa',
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
    title: 'Emanetlerim',
    subtitle: 'Devam eden ve biten işler',
    purpose: 'Yazılı sözleşmeye bağlı tüm emanet işlemleriniz. Onay bekleyen, itirazlı ve tamamlanan kayıtlar tek ekranda.',
    steps: [
      'İşi seçin.',
      'Sözleşme şartlarına göre onay veya itiraz verin.',
      'İtirazda hakem süreci yazılı sözleşmeyi esas alır.',
    ],
    protocolNote: PLAIN_ESCROW_NOTE,
  },
};

/** Gizlenen panel yok — sade 3 sekmelik konsol. */
export const ESCROW_HIDDEN_PANES: readonly ConsolePaneId[] = [];

export const NAV_CARDS: Array<{
  id: ConsolePaneId;
  emoji: string;
  label: string;
  short: string;
  color: 'purple' | 'orange' | 'sky' | 'emerald' | 'amber' | 'indigo';
}> = [
  { id: 'hizmet-al', emoji: '➕', label: 'Emanet başlat', short: 'Alıcı veya satıcı', color: 'purple' },
  { id: 'sozlesmelerim', emoji: '📋', label: 'Emanetlerim', short: 'Onay ve itiraz', color: 'sky' },
];

export const MOBILE_QUICK_LINKS: Array<{
  pane: ConsolePaneId;
  label: string;
  emoji: string;
  scrollTarget?: string;
}> = [
  { pane: 'hizmet-al', label: 'Emanet', emoji: '➕' },
  { pane: 'sozlesmelerim', label: 'Emanetlerim', emoji: '📋' },
  { pane: 'dashboard', label: 'Kasa', emoji: '💼', scrollTarget: 'wallet-center' },
];

export function isPaneVisible(pane: ConsolePaneId): boolean {
  return !ESCROW_HIDDEN_PANES.includes(pane);
}

export function consolePaneAnchorId(pane: ConsolePaneId): string {
  return pane === 'dashboard' ? 'console-dashboard' : `console-pane-${pane}`;
}
