import { LANDING_USE_CASES } from '../../data/landingContent';

export default function LandingUseCases() {
  return (
    <section id="use-cases" className="border-b border-slate-800 bg-slate-950 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <h2 className="text-3xl font-black text-slate-100 sm:text-4xl">Kimler kullanır?</h2>
          <p className="text-sm text-slate-300 sm:text-base">
            Zinesh tarafları bulmaz; dışarıda anlaşmış iş ortaklarının emanet sürecini yönetir.
          </p>
        </div>
        <div className="mt-12 grid grid-cols-1 gap-6 md:grid-cols-3">
          {LANDING_USE_CASES.map((item) => (
            <article
              key={item.id}
              className="rounded-2xl border border-slate-800 bg-slate-900/80 p-6 shadow-lg"
            >
              <p className="text-[10px] font-bold uppercase tracking-wider text-emerald-400">{item.category}</p>
              <h3 className="mt-2 text-lg font-bold text-slate-100">{item.title}</h3>
              <p className="mt-2 text-xs leading-relaxed text-slate-400">{item.description}</p>
              <div className="mt-4 space-y-2 border-t border-slate-800 pt-4 text-xs text-slate-300">
                <p>
                  <span className="font-semibold text-emerald-300">Alıcı:</span> {item.buyerBenefit}
                </p>
                <p>
                  <span className="font-semibold text-emerald-300">Satıcı:</span> {item.sellerBenefit}
                </p>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
