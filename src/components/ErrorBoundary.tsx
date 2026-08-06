import React from 'react';
import { AlertTriangle, LogOut, RefreshCw } from 'lucide-react';
import { clearSessionAndReload } from '../lib/auth';
import { emitZineshEvent } from '../lib/zineshEvents';

type FallbackRenderer = (args: { error: Error; reset: () => void }) => React.ReactNode;

interface ErrorBoundaryProps {
  children: React.ReactNode;
  /** 'page' → tam ekran; 'section' → satır içi kart. Varsayılan 'page'. */
  variant?: 'page' | 'section';
  /** Özel fallback (verilirse varsayılan ekran yerine kullanılır). */
  fallback?: React.ReactNode | FallbackRenderer;
  /** Hata ayıklama/telemetri için ek etiket (hangi bölüm çöktü). */
  label?: string;
  onError?: (error: Error, info: React.ErrorInfo) => void;
}

interface ErrorBoundaryState {
  hasError: boolean;
  error: Error | null;
}

/**
 * Alt ağaçtaki render hatalarını yakalar ve tüm uygulamanın beyaz ekrana
 * düşmesini engeller. Kök seviyesinde (page) ve kritik bölümlerde (section)
 * kullanılır.
 */
export default class ErrorBoundary extends React.Component<ErrorBoundaryProps, ErrorBoundaryState> {
  state: ErrorBoundaryState = { hasError: false, error: null };

  static getDerivedStateFromError(error: Error): ErrorBoundaryState {
    return { hasError: true, error };
  }

  componentDidCatch(error: Error, info: React.ErrorInfo): void {
    const label = this.props.label ?? this.props.variant ?? 'app';
    try {
      emitZineshEvent('client_error', {
        label,
        message: error.message,
        component: info.componentStack?.split('\n')[1]?.trim() ?? null,
      });
    } catch {
      /* telemetri hatası akışı bozmamalı */
    }
    console.error(`[ErrorBoundary:${label}]`, error, info.componentStack);
    this.props.onError?.(error, info);
  }

  reset = (): void => {
    this.setState({ hasError: false, error: null });
  };

  render(): React.ReactNode {
    if (!this.state.hasError || !this.state.error) {
      return this.props.children;
    }

    const { fallback, variant = 'page' } = this.props;
    if (typeof fallback === 'function') {
      return (fallback as FallbackRenderer)({ error: this.state.error, reset: this.reset });
    }
    if (fallback) {
      return fallback;
    }

    if (variant === 'section') {
      return (
        <div
          role="alert"
          className="w-full rounded-2xl border border-red-500/20 bg-red-950/20 px-5 py-6 text-center"
        >
          <AlertTriangle className="mx-auto mb-2 h-6 w-6 text-red-400" />
          <p className="text-sm font-semibold text-zinc-100">Bu bölüm yüklenirken bir sorun oluştu.</p>
          <p className="mt-1 text-xs text-zinc-400">
            Sayfanın geri kalanı çalışmaya devam ediyor.
          </p>
          <button
            type="button"
            onClick={this.reset}
            className="mt-4 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-4 py-2 text-xs font-semibold text-zinc-100 transition hover:bg-white/10"
          >
            <RefreshCw className="h-3.5 w-3.5" />
            Tekrar dene
          </button>
        </div>
      );
    }

    return (
      <div className="flex min-h-screen flex-col items-center justify-center bg-[#030307] px-6 text-center font-sans text-zinc-100">
        <div className="flex h-14 w-14 items-center justify-center rounded-2xl border border-red-500/30 bg-red-950/30">
          <AlertTriangle className="h-7 w-7 text-red-400" />
        </div>
        <h1 className="mt-5 text-lg font-semibold tracking-tight">Beklenmeyen bir hata oluştu</h1>
        <p className="mt-2 max-w-md text-sm leading-relaxed text-zinc-400">
          Uygulamada bir sorunla karşılaştık. Yeniden deneyebilir, sayfayı yenileyebilir veya oturumu
          temizleyip tekrar giriş yapabilirsin.
        </p>
        {import.meta.env.DEV && this.state.error?.message ? (
          <p className="mt-3 max-w-md font-mono text-[11px] text-red-300/80 break-words">
            {this.state.error.message}
          </p>
        ) : null}
        <div className="mt-6 flex flex-wrap items-center justify-center gap-3">
          <button
            type="button"
            onClick={this.reset}
            className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-5 py-2.5 text-sm font-semibold text-zinc-100 transition hover:bg-white/10"
          >
            <RefreshCw className="h-4 w-4" />
            Tekrar dene
          </button>
          <button
            type="button"
            onClick={() => window.location.reload()}
            className="inline-flex items-center gap-2 rounded-full bg-purple-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-purple-500"
          >
            Sayfayı yenile
          </button>
          <button
            type="button"
            onClick={() => clearSessionAndReload()}
            className="inline-flex items-center gap-2 rounded-full border border-amber-500/35 bg-amber-500/10 px-5 py-2.5 text-sm font-semibold text-amber-100 transition hover:bg-amber-500/20"
          >
            <LogOut className="h-4 w-4" />
            Oturumu temizle
          </button>
        </div>
      </div>
    );
  }
}
