import LandingHero from './LandingHero';
import LandingWhatIsZinesh from './LandingWhatIsZinesh';
import LandingUseCases from './LandingUseCases';
import LandingComparison from './LandingComparison';
import LandingSecurity from './LandingSecurity';
import LandingEscrowSimulator from './LandingEscrowSimulator';
import LandingDealConnection from './LandingDealConnection';
import LandingFeeCalculator from './LandingFeeCalculator';
import LandingFaq from './LandingFaq';
import LandingTestimonials from './LandingTestimonials';

type LandingPageProps = {
  isLoggedIn?: boolean;
  onJoinClick: () => void;
  onGoToConsole?: () => void;
};

function scrollToId(id: string) {
  const el = document.getElementById(id);
  if (!el) return;
  const top = Math.max(0, Math.round(el.getBoundingClientRect().top + window.scrollY));
  window.scrollTo({ top, behavior: 'smooth' });
  try {
    history.replaceState(null, '', `#${id}`);
  } catch {
    /* ignore */
  }
}

export default function LandingPage({ isLoggedIn, onJoinClick, onGoToConsole }: LandingPageProps) {
  return (
    <div className="landing-page w-full bg-slate-950 text-slate-100">
      <LandingHero
        isLoggedIn={isLoggedIn}
        onJoinClick={onJoinClick}
        onGoToConsole={onGoToConsole}
        onStartSimulation={() => scrollToId('simulator')}
        onOpenCalculator={() => scrollToId('calculator')}
      />
      <LandingWhatIsZinesh />
      <LandingUseCases />
      <LandingComparison />
      <LandingSecurity />
      <LandingEscrowSimulator />
      <LandingDealConnection
        isLoggedIn={isLoggedIn}
        onJoinClick={onJoinClick}
        onGoToConsole={onGoToConsole}
      />
      <LandingFeeCalculator />
      <LandingTestimonials />
      <LandingFaq />
    </div>
  );
}
