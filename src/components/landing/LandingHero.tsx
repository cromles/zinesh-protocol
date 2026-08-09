import { useState } from 'react';
import { ArrowRight, Lock, Shield, Sparkles, Store, UserCheck } from 'lucide-react';

type LandingHeroProps = {
  onStartSimulation: () => void;
  onOpenCalculator: () => void;
  onJoinClick: () => void;
  onGoToConsole?: () => void;
  isLoggedIn?: boolean;
};

export default function LandingHero({
  onStartSimulation,
  onOpenCalculator,
  onJoinClick,
  onGoToConsole,
  isLoggedIn,
}: LandingHeroProps) {
  const [activeTab, setActiveTab] = useState<'buyer' | 'seller'>('buyer');

  return (
    <section id="hero" className="relative border-b border-slate-800 bg-slate-950 pt-12 pb-20 text-white overflow-hidden">
      <div className="pointer-events-none absolute top-1/4 left-1/2 h-[350px] w-[600px] -translate-x-1/2 -translate-y-1/2 rounded-full bg-emerald-600/10 blur-[120px]" />

      <div className="relative z-10 mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mb-6 flex flex-col items-center gap-2 text-center">
          <div className="inline-flex items-center gap-2 rounded-full border border-emerald-500/30 bg-slate-900/90 px-4 py-1.5 text-xs font-semibold text-emerald-300 shadow-xl backdrop-blur-md">
            <Shield className="h-3.5 w-3.5 text-emerald-400" />
            Zinesh güvenli emanet
          </div>
          <p className="text-xl font-black tracking-wide text-emerald-400 sm:text-2xl">
            Güvenmek zorunda kalmadan anlaşın.
          </p>
          <p className="max-w-2xl text-xs text-slate-300 sm:text-sm">
            Zinesh, tarafların zaten yaptığı anlaşmayı güvenli bir emanet süreciyle korur. Pazaryeri değildir;
            kullanıcı bulmaz veya eşleştirme yapmaz.
          </p>
        </div>

        <div className="mx-auto max-w-4xl space-y-5 text-center">
          <h1 className="text-3xl font-black leading-tight tracking-tight text-slate-100 sm:text-5xl lg:text-6xl">
            Dışarıda anlaşın.
            <br className="hidden sm:inline" />
            <span className="bg-gradient-to-r from-emerald-400 via-teal-300 to-emerald-500 bg-clip-text text-transparent">
              {' '}
              Zinesh&apos;te emanet sürecini yönetin.
            </span>
          </h1>

          <div className="relative mx-auto max-w-3xl rounded-2xl border border-slate-800 bg-slate-900/90 p-5 text-left shadow-2xl sm:p-6">
            <div className="absolute -top-3 left-6 rounded bg-emerald-500 px-2.5 py-0.5 text-[11px] font-black uppercase tracking-wide text-slate-950 shadow">
              Akış
            </div>
            <p className="pt-1 text-sm leading-relaxed text-slate-300 sm:text-base">
              Taraflar fiyat ve teslim koşullarında dışarıda anlaşır. Zinesh&apos;e gelir, karşı tarafı{' '}
              <strong className="text-white">üye numarası</strong> ile bağlar, sözleşmeyi oluşturur ve tutarı emanet
              sürecine alır. İş tamamlanınca onay; sorun olursa anlaşmazlık süreci işler.
            </p>
            <div className="mt-4 grid grid-cols-2 gap-2 border-t border-slate-800/80 pt-3 text-center text-xs font-semibold sm:grid-cols-5">
              {['Anlaş', 'Bağlan', 'Emanet', 'Teslim', 'Onay'].map((label, i) => (
                <div
                  key={label}
                  className={`rounded-lg border p-2 bg-slate-950 ${i === 4 ? 'col-span-2 border-emerald-500/40 text-emerald-300 sm:col-span-1' : 'border-slate-800 text-slate-200'}`}
                >
                  <span className="mb-0.5 block text-[10px] text-emerald-400">{i + 1}</span>
                  {label}
                </div>
              ))}
            </div>
          </div>

          <div className="pt-2">
            <p className="mb-3 text-xs font-semibold uppercase tracking-widest text-slate-400">Tarafınızı seçin</p>
            <div className="inline-flex rounded-xl border border-slate-800 bg-slate-900 p-1">
              {(['buyer', 'seller'] as const).map((tab) => (
                <button
                  key={tab}
                  type="button"
                  onClick={() => setActiveTab(tab)}
                  className={`flex cursor-pointer items-center gap-2 rounded-lg px-5 py-2.5 text-xs font-bold transition-all ${
                    activeTab === tab ? 'bg-emerald-500 text-slate-950 shadow-md' : 'text-slate-400 hover:text-white'
                  }`}
                >
                  {tab === 'buyer' ? <UserCheck className="h-4 w-4" /> : <Store className="h-4 w-4" />}
                  {tab === 'buyer' ? 'İşveren / alıcı' : 'İşçi / satıcı'}
                </button>
              ))}
            </div>
            <div className="mx-auto mt-4 max-w-2xl rounded-2xl border border-slate-800/80 bg-slate-900/60 p-5 text-left backdrop-blur-sm">
              {activeTab === 'buyer' ? (
                <p className="text-xs leading-relaxed text-slate-300">
                  <Lock className="mr-1 inline h-4 w-4 text-emerald-400" />
                  Tutar kilitlenmeden teslim onayı verilmez. Karşı tarafı üye numarasıyla bağlayıp sözleşmeyi
                  oluşturursunuz.
                </p>
              ) : (
                <p className="text-xs leading-relaxed text-slate-300">
                  <Shield className="mr-1 inline h-4 w-4 text-emerald-400" />
                  Karşı tarafın tutarı emanet sürecine aldığını panelde görürsünüz; teslim ve onay adımları kayıt altına
                  alınır.
                </p>
              )}
            </div>
          </div>

          <div className="flex flex-col items-center justify-center gap-3 pt-4 sm:flex-row">
            <button
              type="button"
              onClick={isLoggedIn ? (onGoToConsole ?? onJoinClick) : onJoinClick}
              className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-emerald-400 via-teal-300 to-emerald-400 px-7 py-4 text-sm font-black text-slate-950 shadow-xl shadow-emerald-950/50 transition-all hover:-translate-y-0.5 hover:opacity-95 sm:w-auto"
            >
              {isLoggedIn ? 'Konsola git' : 'Kayıt ol / Giriş yap'}
              <ArrowRight className="h-4 w-4" />
            </button>
            <button
              type="button"
              onClick={onStartSimulation}
              className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-6 py-4 text-sm font-bold text-slate-200 transition-all hover:bg-slate-800 sm:w-auto"
            >
              <Sparkles className="h-4 w-4 text-emerald-400" />
              Demo simülatör (SİMÜLASYON)
            </button>
            <button
              type="button"
              onClick={onOpenCalculator}
              className="flex w-full cursor-pointer items-center justify-center gap-2 rounded-xl border border-slate-700 bg-slate-900 px-6 py-4 text-sm font-bold text-slate-200 transition-all hover:bg-slate-800 sm:w-auto"
            >
              Komisyon hesapla
            </button>
          </div>
        </div>
      </div>
    </section>
  );
}
