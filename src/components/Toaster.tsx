import React, { useSyncExternalStore } from 'react';
import { motion, AnimatePresence } from 'motion/react';
import { CheckCircle2, AlertTriangle, Info, XCircle, X } from 'lucide-react';
import {
  dismissToast,
  getToastsSnapshot,
  subscribeToasts,
  type ToastItem,
  type ToastType,
} from '../lib/toast';

const TYPE_STYLES: Record<
  ToastType,
  { icon: React.ComponentType<{ className?: string }>; ring: string; iconColor: string; role: 'status' | 'alert' }
> = {
  success: {
    icon: CheckCircle2,
    ring: 'border-emerald-500/30 shadow-[0_0_24px_rgba(16,185,129,0.12)]',
    iconColor: 'text-emerald-400',
    role: 'status',
  },
  error: {
    icon: XCircle,
    ring: 'border-red-500/30 shadow-[0_0_24px_rgba(239,68,68,0.14)]',
    iconColor: 'text-red-400',
    role: 'alert',
  },
  warning: {
    icon: AlertTriangle,
    ring: 'border-amber-500/30 shadow-[0_0_24px_rgba(245,158,11,0.14)]',
    iconColor: 'text-amber-400',
    role: 'alert',
  },
  info: {
    icon: Info,
    ring: 'border-sky-500/30 shadow-[0_0_24px_rgba(56,189,248,0.12)]',
    iconColor: 'text-sky-400',
    role: 'status',
  },
};

function ToastCard({ item }: { item: ToastItem }) {
  const { icon: Icon, ring, iconColor, role } = TYPE_STYLES[item.type];
  return (
    <motion.div
      layout
      role={role}
      aria-live={role === 'alert' ? 'assertive' : 'polite'}
      initial={{ opacity: 0, y: 16, scale: 0.96 }}
      animate={{ opacity: 1, y: 0, scale: 1 }}
      exit={{ opacity: 0, x: 24, scale: 0.96, transition: { duration: 0.18 } }}
      transition={{ type: 'spring', stiffness: 420, damping: 32 }}
      className={`pointer-events-auto flex w-full items-start gap-3 rounded-2xl border bg-[#0a0a10]/95 px-4 py-3 backdrop-blur-md ${ring}`}
    >
      <Icon className={`mt-0.5 h-5 w-5 shrink-0 ${iconColor}`} />
      <p className="min-w-0 flex-1 whitespace-pre-line break-words text-sm leading-relaxed text-zinc-100">
        {item.message}
      </p>
      <button
        type="button"
        onClick={() => dismissToast(item.id)}
        aria-label="Bildirimi kapat"
        className="-mr-1 -mt-0.5 shrink-0 rounded-lg p-1 text-zinc-500 transition hover:bg-white/5 hover:text-zinc-200"
      >
        <X className="h-4 w-4" />
      </button>
    </motion.div>
  );
}

export default function Toaster() {
  const toasts = useSyncExternalStore(subscribeToasts, getToastsSnapshot, getToastsSnapshot);

  return (
    <div
      aria-label="Bildirimler"
      className="pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-center gap-2 px-3 pb-4 sm:inset-x-auto sm:right-4 sm:bottom-4 sm:w-[380px] sm:max-w-[calc(100vw-2rem)] sm:items-end sm:px-0"
    >
      <AnimatePresence initial={false}>
        {toasts.map((item) => (
          <ToastCard key={item.id} item={item} />
        ))}
      </AnimatePresence>
    </div>
  );
}
