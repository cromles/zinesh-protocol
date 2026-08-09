import { Quote, Star } from 'lucide-react';
import { LANDING_TESTIMONIALS } from '../../data/landingContent';

export default function LandingTestimonials() {
  return (
    <section className="border-b border-slate-800 bg-slate-900 py-20 text-white">
      <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
        <div className="mx-auto max-w-3xl space-y-3 text-center">
          <div className="inline-flex items-center gap-1.5 rounded-full border border-amber-500/30 bg-amber-500/10 px-3 py-1 text-xs font-semibold uppercase tracking-wider text-amber-300">
            Örnek senaryolar
          </div>
          <h2 className="text-3xl font-black text-slate-100 sm:text-4xl">Kullanım örnekleri</h2>
          <p className="text-sm leading-relaxed text-slate-300 sm:text-base">
            Aşağıdaki yorumlar gerçek kullanıcı verisi değildir; tipik kullanım senaryolarını gösterir.
          </p>
        </div>

        <div className="mt-12 grid grid-cols-1 gap-6 md:grid-cols-2">
          {LANDING_TESTIMONIALS.map((t) => (
            <article
              key={t.id}
              className="relative flex flex-col justify-between rounded-2xl border border-slate-800 bg-slate-950 p-6 shadow-xl"
            >
              <Quote className="absolute right-4 top-4 h-8 w-8 text-emerald-500/20" />
              <div className="space-y-3">
                <div className="flex items-center gap-1 text-amber-400">
                  {Array.from({ length: t.rating }).map((_, i) => (
                    <Star key={i} className="h-3.5 w-3.5 fill-amber-400" />
                  ))}
                </div>
                <p className="text-xs italic leading-relaxed text-slate-300">&ldquo;{t.comment}&rdquo;</p>
              </div>
              <div className="mt-6 flex items-center justify-between border-t border-slate-800 pt-4 text-xs">
                <div>
                  <div className="font-bold text-slate-100">{t.name}</div>
                  <div className="text-[11px] text-slate-400">{t.role}</div>
                </div>
                <span className="rounded border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 font-mono text-[10px] font-bold text-amber-300">
                  ÖRNEK
                </span>
              </div>
            </article>
          ))}
        </div>
      </div>
    </section>
  );
}
