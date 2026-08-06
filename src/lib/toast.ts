/**
 * Hafif, bloklayıcı olmayan toast (bildirim) sistemi.
 *
 * `alert()` yerine kullanılır: modül seviyesinde çağrılabilir bir `toast` API'si
 * ile React ağacının herhangi bir yerinden (hatta bileşen dışından) tetiklenebilir.
 * Ekranda tek bir <Toaster/> bileşeni bu store'a abone olur.
 */

export type ToastType = 'success' | 'error' | 'info' | 'warning';

export interface ToastItem {
  id: string;
  message: string;
  type: ToastType;
  /** Otomatik kapanma süresi (ms). 0 → elle kapatılana kadar kalır. */
  duration: number;
}

export interface ToastOptions {
  type?: ToastType;
  duration?: number;
}

const DEFAULT_DURATION: Record<ToastType, number> = {
  success: 4000,
  info: 4000,
  warning: 6000,
  error: 6000,
};

let toasts: ToastItem[] = [];
const listeners = new Set<() => void>();
const timers = new Map<string, ReturnType<typeof setTimeout>>();

function emit(): void {
  for (const listener of listeners) listener();
}

function createId(): string {
  return `toast-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 8)}`;
}

export function subscribeToasts(listener: () => void): () => void {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
}

export function getToastsSnapshot(): ToastItem[] {
  return toasts;
}

export function dismissToast(id: string): void {
  const timer = timers.get(id);
  if (timer) {
    clearTimeout(timer);
    timers.delete(id);
  }
  const next = toasts.filter((t) => t.id !== id);
  if (next.length !== toasts.length) {
    toasts = next;
    emit();
  }
}

export function clearToasts(): void {
  for (const timer of timers.values()) clearTimeout(timer);
  timers.clear();
  if (toasts.length > 0) {
    toasts = [];
    emit();
  }
}

export function showToast(message: string, options: ToastOptions = {}): string {
  const type = options.type ?? 'info';
  const duration = options.duration ?? DEFAULT_DURATION[type];
  const id = createId();
  const item: ToastItem = { id, message, type, duration };

  // En fazla 4 toast göster; en eskiyi düşür.
  const trimmed = toasts.length >= 4 ? toasts.slice(toasts.length - 3) : toasts;
  toasts = [...trimmed, item];
  emit();

  if (duration > 0) {
    const timer = setTimeout(() => dismissToast(id), duration);
    timers.set(id, timer);
  }

  return id;
}

export const toast = {
  show: showToast,
  success: (message: string, options?: Omit<ToastOptions, 'type'>) =>
    showToast(message, { ...options, type: 'success' }),
  error: (message: string, options?: Omit<ToastOptions, 'type'>) =>
    showToast(message, { ...options, type: 'error' }),
  info: (message: string, options?: Omit<ToastOptions, 'type'>) =>
    showToast(message, { ...options, type: 'info' }),
  warning: (message: string, options?: Omit<ToastOptions, 'type'>) =>
    showToast(message, { ...options, type: 'warning' }),
  dismiss: dismissToast,
  clear: clearToasts,
};
