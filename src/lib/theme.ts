export type ThemeMode = 'dark' | 'light';

const STORAGE_KEY = 'zinesh-theme';
const listeners = new Set<() => void>();

export function getTheme(): ThemeMode {
  if (typeof document === 'undefined') {
    return 'dark';
  }
  return document.documentElement.getAttribute('data-theme') === 'light' ? 'light' : 'dark';
}

export function setTheme(mode: ThemeMode): void {
  if (typeof document === 'undefined') {
    return;
  }
  if (mode === 'light') {
    document.documentElement.setAttribute('data-theme', 'light');
  } else {
    document.documentElement.removeAttribute('data-theme');
  }
  try {
    localStorage.setItem(STORAGE_KEY, mode);
  } catch {
    /* ignore */
  }
  const themeColor = document.querySelector('meta[name="theme-color"]');
  themeColor?.setAttribute('content', mode === 'light' ? '#ffffff' : '#06060a');
  listeners.forEach((listener) => listener());
}

export function toggleTheme(): void {
  setTheme(getTheme() === 'dark' ? 'light' : 'dark');
}

export function initTheme(): void {
  try {
    if (localStorage.getItem(STORAGE_KEY) === 'light') {
      setTheme('light');
    }
  } catch {
    /* ignore */
  }
}

export function subscribeTheme(listener: () => void): () => void {
  listeners.add(listener);
  return () => listeners.delete(listener);
}
