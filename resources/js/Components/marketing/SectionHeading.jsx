import Reveal from '@/Components/motion/Reveal';

/**
 * One heading style for every marketing section: eyebrow pill + display
 * title + optional subtitle, light/dark tone, center/left align.
 */
export default function SectionHeading(props) {
    const badge = props.badge;
    const title = props.title;
    const subtitle = props.subtitle;
    const tone = props.tone || 'light';
    const align = props.align || 'center';
    const className = props.className || '';
    if (!title) return null;
    const dark = tone === 'dark';
    const alignCls = align === 'center' ? 'mx-auto max-w-3xl text-center' : 'max-w-2xl';
    const eyebrowCls = dark
        ? 'border-white/15 bg-white/5 text-brand-300'
        : 'border-[#D5E5C9] bg-white text-[#345927]';
    const dotCls = dark ? 'bg-brand-400' : 'bg-brand-600';
    const titleCls = dark ? 'text-white' : 'text-[#0F1A0C]';
    const subCls = dark ? 'text-white/70' : 'text-[#4A5B44]';
    return (
        <div className={'mb-12 sm:mb-16 ' + alignCls + ' ' + className}>
            {badge ? (
                <Reveal as="div" origin="bottom" distance={15} duration={600}>
                    <span className={'inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-[11px] font-bold uppercase tracking-[0.14em] ' + eyebrowCls}>
                        <span className={'inline-block h-1.5 w-1.5 rounded-full ' + dotCls} />
                        {badge}
                    </span>
                </Reveal>
            ) : null}
            <Reveal as="div" origin="bottom" distance={20} duration={700} delay={badge ? 80 : 0}>
                <h2 className={'mt-4 font-space text-3xl font-bold leading-[1.14] tracking-[-0.02em] sm:text-4xl lg:text-[44px] ' + titleCls}>
                    {title}
                </h2>
            </Reveal>
            {subtitle ? (
                <Reveal as="div" origin="bottom" distance={20} duration={700} delay={160}>
                    <p className={'mt-4 text-base leading-relaxed sm:text-lg ' + subCls}>{subtitle}</p>
                </Reveal>
            ) : null}
        </div>
    );
}
