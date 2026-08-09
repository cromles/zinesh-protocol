import { useEffect, useState } from 'react';
import {
  AlertTriangle,
  CheckCircle2,
  Lock,
  RotateCcw,
  Shield,
  Sparkles,
  User,
} from 'lucide-react';
import {
  calculateEscrowFee,
  formatCommissionPercent,
} from '../../lib/protocolRates';

const STORAGE_KEY = 'zinesh_landing_demo_simulator_v1';

type SimStep = 1 | 2 | 3 | 4;
type SimRole = 'buyer' | 'seller';
type SimOutcome = 'approved' | 'disputed' | null;

export default function LandingEscrowSimulator() {
  const savedState = (() => {
    try {
      const item = localStorage.getItem(STORAGE_KEY);
      return item ? JSON.parse(item) : null;
    } catch {
      return null;
    }
  })();

  const [step, setStep] = useState<SimStep>(savedState?.step ?? 1);
  const [role, setRole] = useState<SimRole>(savedState?.role ?? 'buyer');
  const [partnerId, setPartnerId] = useState(savedState?.partnerId ?? '88214');
  const [title, setTitle] = useState(savedState?.title ?? 'Örnek domain devri');
  const [amount, setAmount] = useState(savedState?.amount ?? 25000);
  const [outcome, setOutcome] = useState<SimOutcome>(savedState?.outcome ?? null);
  const [isDepositing, setIsDepositing] = useState(false);

  useEffect(() => {
    try {
      localStorage.setItem(
        STORAGE_KEY,
        JSON.stringify({ step, role, partnerId, title, amount, outcome })
      );
    } catch {
      /* ignore */
    }
  }, [step, role, partnerId, title, amount, outcome]);

  const totalFee = calculateEscrowFee(amount);
  const buyerTotal = amount + totalFee;

  const handleDeposit = () => {
    setIsDepositing(true);
    setTimeout(() => {
      setIsDepositing(false);
      setStep(3);
    }, 1200);
  };

  const handleReset = () => {
    try {
      localStorage.removeItem(STORAGE_KEY);
    } catch {
      /* ignore */
    }
    setStep(1);
    setOutcome(null);
    setIsDepositing(false);
  };

  return (
    <section id="simulator" className="relative border-b border-slate-800 bg-slate-950 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-amber-500/40 bg-amber-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-amber-300">
            <Sparkles className="h-3.5 w-3.5" />
            SİMÜLASYON — gerçek escrow değil
          </div>
          <h2 className="text-3xl font-black text-slate-100 sm:text-4xl">Emanet süreci demo simülatörü</h2>
          <p className="text-sm leading-relaxed text-slate-300 sm:text-base">
            Kayıt olmadan akışı deneyin. Gerçek işlemler için konsoldaki emanet odasını kullanın.
          </p>
        </div>

        <div className="mx-auto mt-10 max-w-4xl">
          <div className="grid grid-cols-4 gap-2 rounded-2xl border border-slate-800 bg-slate-900 p-2">
            {['Detay', 'Kilit', 'Teslim', 'Sonuç'].map((label, i) => {
              const num = (i + 1) as SimStep;
              return (
                <div
                  key={label}
                  className={`rounded-xl px-2 py-2.5 text-center text-xs font-bold transition-all ${
                    step === num
                      ? 'bg-emerald-500 text-slate-950 shadow'
                      : step > num
                        ? 'bg-slate-800 text-emerald-400'
                        : 'text-slate-500'
                  }`}
                >
                  <span className="hidden sm:inline">{i + 1}. {label}</span>
                  <span className="sm:hidden">{i + 1}</span>
                </div>
              );
            })}
          </div>
        </div>

        <div className="relative mx-auto mt-8 max-w-4xl overflow-hidden rounded-3xl border border-slate-800 bg-slate-900 p-6 shadow-2xl sm:p-8">
          {step === 1 && (
            <div className="space-y-6">
              <div className="border-b border-slate-800 pb-4">
                <h3 className="flex items-center gap-2 text-xl font-bold text-slate-100">
                  <Shield className="h-5 w-5 text-emerald-400" />
                  Adım 1: İşlem detayları
                </h3>
                <p className="text-xs text-slate-400">Sanal senaryo — backend&apos;e bağlı değildir.</p>
              </div>

              <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div>
                  <label className="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-300">
                    Rolünüz
                  </label>
                  <div className="grid grid-cols-2 gap-2">
                    {(['buyer', 'seller'] as const).map((r) => (
                      <button
                        key={r}
                        type="button"
                        onClick={() => setRole(r)}
                        className={`flex cursor-pointer items-center gap-2 rounded-xl border p-3 text-left transition-all ${
                          role === r
                            ? 'border-emerald-500 bg-emerald-500/10 font-bold text-emerald-300'
                            : 'border-slate-800 bg-slate-950 text-slate-400 hover:text-white'
                        }`}
                      >
                        <User className="h-4 w-4 text-emerald-400" />
                        <span className="text-xs">{r === 'buyer' ? 'İşveren / alıcı' : 'İşçi / satıcı'}</span>
                      </button>
                    ))}
                  </div>
                </div>

                <div>
                  <label className="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-300">
                    Karşı taraf üye no
                  </label>
                  <input
                    type="text"
                    value={partnerId}
                    onChange={(e) => setPartnerId(e.target.value.replace(/\D/g, '').slice(0, 5))}
                    className="w-full rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 font-mono text-sm text-emerald-400 focus:border-emerald-500 focus:outline-none"
                  />
                </div>

                <div>
                  <label className="mb-2 block text-xs font-bold uppercase tracking-wider text-slate-300">
                    İşlem adı
                  </label>
                  <input
                    type="text"
                    value={title}
                    onChange={(e) => setTitle(e.target.value)}
                    className="w-full rounded-xl border border-slate-800 bg-slate-950 px-4 py-3 text-sm text-slate-200 focus:border-emerald-500 focus:outline-none"
                  />
                </div>

                <div>
                  <div className="mb-2 flex items-center justify-between">
                    <label className="text-xs font-bold uppercase tracking-wider text-slate-300">Tutar</label>
                    <span className="font-mono text-base font-black text-emerald-400">
                      ₺{amount.toLocaleString('tr-TR')}
                    </span>
                  </div>
                  <input
                    type="range"
                    min={2000}
                    max={200000}
                    step={1000}
                    value={amount}
                    onChange={(e) => setAmount(Number(e.target.value))}
                    className="w-full cursor-pointer accent-emerald-400"
                  />
                </div>
              </div>

              <button
                type="button"
                onClick={() => setStep(2)}
                className="w-full cursor-pointer rounded-xl bg-emerald-500 py-3 text-sm font-bold text-slate-950 hover:bg-emerald-400"
              >
                Sözleşme taslağını onayla (simülasyon)
              </button>
            </div>
          )}

          {step === 2 && (
            <div className="space-y-6 text-center">
              <Lock className="mx-auto h-12 w-12 text-emerald-400" />
              <h3 className="text-xl font-bold text-slate-100">Adım 2: Emanet kilidi (simülasyon)</h3>
              <p className="text-xs text-slate-400">
                {role === 'buyer'
                  ? `Alıcı olarak ₺${buyerTotal.toLocaleString('tr-TR')} (tutar + ${formatCommissionPercent()} komisyon) emanet sürecine alınır.`
                  : 'Satıcı olarak karşı tarafın tutarı kilitlediğini görürsünüz.'}
              </p>
              <button
                type="button"
                disabled={isDepositing}
                onClick={handleDeposit}
                className="mx-auto flex cursor-pointer items-center gap-2 rounded-xl bg-emerald-500 px-8 py-3 text-sm font-bold text-slate-950 hover:bg-emerald-400 disabled:opacity-60"
              >
                {isDepositing ? 'Kilitleniyor...' : 'Tutarı kilitle (simülasyon)'}
              </button>
            </div>
          )}

          {step === 3 && (
            <div className="space-y-6">
              <h3 className="text-xl font-bold text-slate-100">Adım 3: Teslim ve onay (simülasyon)</h3>
              <p className="text-xs text-slate-400">
                &ldquo;{title}&rdquo; — ZN-{partnerId.padStart(5, '0')} ile bağlantı. Gerçek süreç konsolda yürütülür.
              </p>
              <div className="flex flex-col gap-3 sm:flex-row sm:justify-center">
                <button
                  type="button"
                  onClick={() => {
                    setOutcome('approved');
                    setStep(4);
                  }}
                  className="flex cursor-pointer items-center justify-center gap-2 rounded-xl bg-emerald-500 px-6 py-3 text-sm font-bold text-slate-950 hover:bg-emerald-400"
                >
                  <CheckCircle2 className="h-4 w-4" />
                  Onayla ve serbest bırak
                </button>
                <button
                  type="button"
                  onClick={() => {
                    setOutcome('disputed');
                    setStep(4);
                  }}
                  className="flex cursor-pointer items-center justify-center gap-2 rounded-xl border border-rose-500/40 bg-rose-950/30 px-6 py-3 text-sm font-bold text-rose-300 hover:bg-rose-950/50"
                >
                  <AlertTriangle className="h-4 w-4" />
                  Anlaşmazlık bildir
                </button>
              </div>
            </div>
          )}

          {step === 4 && (
            <div className="space-y-6 text-center">
              {outcome === 'approved' ? (
                <>
                  <CheckCircle2 className="mx-auto h-12 w-12 text-emerald-400" />
                  <h3 className="text-xl font-bold text-emerald-400">Simülasyon tamamlandı — onay</h3>
                  <p className="text-xs text-slate-400">
                    Gerçek işlemde ödeme konsol onayı sonrası serbest bırakılır.
                  </p>
                </>
              ) : (
                <>
                  <AlertTriangle className="mx-auto h-12 w-12 text-amber-400" />
                  <h3 className="text-xl font-bold text-amber-300">Simülasyon — anlaşmazlık</h3>
                  <p className="text-xs text-slate-400">
                    Gerçek işlemde fonlar kilitli kalır ve anlaşmazlık süreci başlar.
                  </p>
                </>
              )}
              <button
                type="button"
                onClick={handleReset}
                className="mx-auto flex cursor-pointer items-center gap-2 rounded-xl border border-slate-700 bg-slate-800 px-6 py-3 text-sm font-bold text-slate-200 hover:bg-slate-700"
              >
                <RotateCcw className="h-4 w-4" />
                Simülasyonu sıfırla
              </button>
            </div>
          )}
        </div>
      </div>
    </section>
  );
}
