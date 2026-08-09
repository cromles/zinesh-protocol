import { Moon, Sun } from 'lucide-react';
import { useSyncExternalStore } from 'react';
import { getTheme, subscribeTheme, toggleTheme, type ThemeMode } from '../lib/theme';

type ThemeToggleProps = {
  /** full = mobil menü satırı; inline = header/sidebar kompakt */
  layout?: 'full' | 'inline';
  className?: string;
};

function useThemeMode(): ThemeMode {
  return useSyncExternalStore(subscribeTheme, getTheme, () => 'dark');
}

export default function ThemeToggle({ layout = 'inline', className = '' }: ThemeToggleProps) {
  const theme = useThemeMode();
  const isLight = theme === 'light';
  const label = isLight ? 'Koyu Tema' : 'Açık Tema';
  const Icon = isLight ? Moon : Sun;

  if (layout === 'full') {
    return (
      <button
        type="button"
        onClick={toggleTheme}
        className={`flex w-full min-h-[44px] items-center justify-between gap-3 rounded-xl border border-slate-800 bg-slate-900/80 px-4 py-3 text-sm font-semibold text-slate-200 transition hover:border-emerald-500/30 hover:text-slate-100 ${className}`}
        aria-label={label}
      >
        <span className="flex items-center gap-2.5">
          <Icon className="h-4 w-4 shrink-0 text-emerald-400" aria-hidden />
          {label}
        </span>
        <span className="text-[10px] font-mono uppercase tracking-wider text-slate-500">
          {isLight ? 'Açık' : 'Koyu'}
        </span>
      </button>
    );
  }

  return (
    <button
      type="button"
      onClick={toggleTheme}
      className={`inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-800 bg-slate-900/80 text-slate-200 transition hover:border-emerald-500/30 hover:text-emerald-100 ${className}`}
      title={label}
      aria-label={label}
    >
      <Icon className="h-4 w-4" aria-hidden />
    </button>
  );
}
