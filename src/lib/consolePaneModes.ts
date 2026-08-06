import type { ConsolePaneId } from '../components/consolePanelCopy';

export function showsPlatformWallet(pane: ConsolePaneId): boolean {
  return pane === 'dashboard';
}
