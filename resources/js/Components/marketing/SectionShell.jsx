/**
 * Unified marketing section wrapper - one of three fixed brand bands:
 *   light (#FAFDF7) · tint (#F2F7EC) · dark (#162610)
 * Dark bands share the same grid + glow decor so every section on the
 * marketing surface reads as one design system. Decor is static, so
 * nothing extra to disable under prefers-reduced-motion.
 */
const VARIANTS = {
    light: 'bg-[#FAFDF7] text-[#0A0A0B]',
    tint: 'bg-[#F2F7EC] text-[#0A0A0B]',
    dark: 'bg-[#162610] text-white',
};

export default function SectionShell(props) {
    const variant = props.variant || 'light';
    const className = props.className || 'py-20 sm:py-28';
    const dark = variant === 'dark';
    const base = 'relative overflow-hidden ';
    return (
        <section id={props.id} className={base + (VARIANTS[variant] || VARIANTS.light) + ' ' + className}>
            <div className="pointer-events-none absolute inset-0">
                {dark ? <div className="wm-grid-dark absolute inset-0" /> : null}
                {dark ? <div className="wm-glow absolute left-1/2 top-0 -translate-x-1/2" /> : null}
            </div>
            <div className="relative z-10">{props.children}</div>
        </section>
    );
}
