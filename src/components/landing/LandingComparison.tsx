import { Check, Shield, X } from 'lucide-react';
import { formatCommissionPercent } from '../../lib/protocolRates';

export default function LandingComparison() {
  const rateLabel = formatCommissionPercent();

  return (
    <section className="border-b border-slate-800 bg-slate-900 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-emerald-500/30 bg-emerald-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-emerald-400">
            <Shield className="h-3.5 w-3.5" /> Karşılaştırma
          </div>
          <h2 className="text-3xl font-black text-slate-100 sm:text-4xl">Neden direkt ödeme değil de Zinesh?</h2>
          <p className="text-sm leading-relaxed text-slate-300 sm:text-base">
            Zinesh pazaryeri değildir; dışarıda anlaşmış tarafların emanet sürecini yönetir.
          </p>
        </div>

        <div className="mt-12 overflow-x-auto">
          <table className="w-full min-w-[640px] border-collapse text-left">
            <thead>
              <tr className="border-b border-slate-800">
                <th className="w-1/4 p-4 text-xs font-bold uppercase tracking-wider text-slate-400">Özellik</th>
                <th className="w-1/4 rounded-t-xl border-t-2 border-emerald-400 bg-emerald-950/40 p-4 text-center text-sm font-black text-emerald-400">
                  ZINESH EMANET
                </th>
                <th className="w-1/4 p-4 text-center text-xs font-bold text-slate-300">Direkt ödeme</th>
                <th className="w-1/4 p-4 text-center text-xs font-bold text-slate-300">Klasik pazaryeri</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-800/60 text-xs text-slate-300">
              <tr>
                <td className="p-4 font-semibold text-slate-200">Emanet kilidi</td>
                <td className="bg-emerald-950/20 p-4 text-center font-bold text-emerald-400">
                  <Check className="mx-auto h-4 w-4 text-emerald-400" />
                </td>
                <td className="p-4 text-center text-slate-500">
                  <X className="mx-auto h-4 w-4 text-slate-600" />
                </td>
                <td className="p-4 text-center text-amber-300">Kısıtlı</td>
              </tr>
              <tr>
                <td className="p-4 font-semibold text-slate-200">Yazılı sözleşme kaydı</td>
                <td className="bg-emerald-950/20 p-4 text-center font-bold text-emerald-400">
                  <Check className="mx-auto h-4 w-4 text-emerald-400" />
                </td>
                <td className="p-4 text-center text-slate-500">
                  <X className="mx-auto h-4 w-4 text-slate-600" />
                </td>
                <td className="p-4 text-center text-slate-400">Standart şartlar</td>
              </tr>
              <tr>
                <td className="p-4 font-semibold text-slate-200">Anlaşmazlık süreci</td>
                <td className="bg-emerald-950/20 p-4 text-center font-bold text-emerald-400">
                  <Check className="mx-auto h-4 w-4 text-emerald-400" />
                </td>
                <td className="p-4 text-center text-slate-500">
                  <X className="mx-auto h-4 w-4 text-slate-600" />
                </td>
                <td className="p-4 text-center text-slate-400">Platform kuralları</td>
              </tr>
              <tr>
                <td className="p-4 font-semibold text-slate-200">Komisyon modeli</td>
                <td className="rounded-b-xl bg-emerald-950/20 p-4 text-center font-mono font-black text-emerald-400">
                  {rateLabel} (protokol sabiti)
                </td>
                <td className="p-4 text-center font-mono text-slate-400">Güvence yok</td>
                <td className="p-4 text-center font-mono font-bold text-rose-400">%15 – %25</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  );
}
