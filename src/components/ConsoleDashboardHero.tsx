import React from 'react';



interface ConsoleDashboardHeroProps {

  userName: string;

}



export default function ConsoleDashboardHero({ userName }: ConsoleDashboardHeroProps) {

  return (

    <section className="relative overflow-hidden rounded-3xl border border-slate-800 bg-gradient-to-br from-emerald-950/40 via-slate-950 to-slate-900 p-5 shadow-[0_0_48px_rgba(16,185,129,0.08)] sm:p-7">

      <div className="pointer-events-none absolute -right-10 -top-16 h-40 w-40 rounded-full bg-emerald-500/15 blur-3xl" />

      <div className="pointer-events-none absolute -bottom-20 -left-10 h-44 w-44 rounded-full bg-teal-500/10 blur-3xl" />



      <div className="relative min-w-0 space-y-2.5 text-left">

        <p className="text-[10px] font-mono font-semibold uppercase tracking-wider text-emerald-400/90">

          Konsol

        </p>

        <div>

          <h1 className="font-display text-xl font-black leading-tight text-white sm:text-2xl">

            Merhaba,{' '}

            <span className="bg-gradient-to-r from-emerald-200 via-white to-teal-200 bg-clip-text text-transparent">

              {userName}

            </span>

          </h1>

          <p className="mt-1 text-xs text-slate-400">Güvenmek zorunda kalmadan anlaşın — emanet sürecinizi yönetin.</p>

        </div>

      </div>

    </section>

  );

}

