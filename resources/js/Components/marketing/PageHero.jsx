import Reveal from '@/Components/motion/Reveal';

/**
 * Unified hero band for marketing sub-pages: dark brand band with shared
 * grid + glow, eyebrow/title/subtitle. Extra content (e.g. a billing
 * toggle) goes in as children below the copy.
 */
export default function PageHero(props) {
    const badge = props.badge;
    const title = props.title;
    const subtitle = props.subtitle;
    return (
        <section className="relative overflow-hidden bg-[#162610] text-white">
            <div className="wm-grid-dark pointer-events-none absolute inset-0" />
            <div className="wm-glow pointer-events-none absolute left-1/2 top-0 -translate-x-1/2" />
            <div className="relative z-10 mx-auto max-w-3xl px-4 py-20 text-center sm:px-6 sm:py-24 lg:px-8">
                <Reveal origin="bottom" distance={30} duration={800}>
                    {badge ? (
                        <span className="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3.5 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] text-brand-300">
                            <span className="inline-block h-1.5 w-1.5 rounded-full bg-brand-400" />
                            {badge}
                        </span>
                    ) : null}
                    <h1 className="mt-4 font-space text-4xl font-bold leading-[1.1] tracking-[-0.02em] sm:text-5xl">{title}</h1>
                    {subtitle ? (
                        <p className="mx-auto mt-4 max-w-xl text-base leading-relaxed text-white/70 sm:text-lg">{subtitle}</p>
                    ) : null}
                </Reveal>
                {props.children}
            </div>
        </section>
    );
}
