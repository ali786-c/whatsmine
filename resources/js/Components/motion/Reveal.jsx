import { useEffect, useRef, useState } from 'react';

/**
 * Scroll-reveal wrapper — the React replacement for ScrollReveal.js used by
 * the reference front page (docs/plans/landing-animation-refresh.md).
 *
 * Behavior mirrors the reference config: `origin` (top/bottom/left/right),
 * `distance` in px, `duration` ms, `delay` ms, and `interval` × `index` for
 * the staggered card grids the reference does with `interval: 100`.
 *
 * Safety properties:
 *  - SSR / no-JS: content renders fully visible (the hidden state is only
 *    applied after mount), so crawlers and no-JS users never see blank space.
 *  - prefers-reduced-motion: the observer never hides content at all.
 *  - `once` (default true): elements animate in a single time and stay put,
 *    matching the reference `reset: false`.
 */
export default function Reveal({
    as: Tag = 'div',
    origin = 'bottom',
    distance = 60,
    duration = 900,
    delay = 0,
    interval = 0,
    index = 0,
    once = true,
    onVisible,
    className = '',
    children,
    ...rest
}) {
    const ref = useRef(null);
    // hidden starts false → first render (and no-JS) is fully visible.
    const [hidden, setHidden] = useState(false);
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        if (typeof window === 'undefined') return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        setHidden(true);

        let revealed = false;
        let raf = null;
        let io = null;

        const reveal = () => {
            if (revealed) return;
            revealed = true;
            setVisible(true);
            if (typeof onVisible === 'function') onVisible();
            if (once) {
                if (io) io.disconnect();
                window.removeEventListener('scroll', onPassed);
            }
        };

        // Fallback: IntersectionObserver only fires on threshold crossings, so
        // an element that jumps from below the viewport to above it in one
        // fast flick (End key, scrollbar drag) never fires a callback and
        // would stay stuck at opacity 0. This rAF-throttled scroll check
        // catches exactly that case — and elements already passed at mount
        // (anchor navigation).
        const onPassed = () => {
            if (raf !== null || revealed) return;
            raf = requestAnimationFrame(() => {
                raf = null;
                if (revealed) return;
                if (el.getBoundingClientRect().bottom < 0) reveal();
            });
        };

        io = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting || entry.boundingClientRect.bottom < 0) {
                    reveal();
                } else if (!once) {
                    setVisible(false);
                    revealed = false;
                }
            },
            { threshold: 0.15, rootMargin: '0px 0px -40px 0px' }
        );
        io.observe(el);
        window.addEventListener('scroll', onPassed, { passive: true });
        onPassed();

        return () => {
            revealed = true; // no reveals after unmount / effect re-run
            io.disconnect();
            window.removeEventListener('scroll', onPassed);
            if (raf !== null) cancelAnimationFrame(raf);
        };
    }, [once, onVisible]);

    const offset = {
        top: `translateY(-${distance}px)`,
        bottom: `translateY(${distance}px)`,
        left: `translateX(-${distance}px)`,
        right: `translateX(${distance}px)`,
    }[origin] || `translateY(${distance}px)`;

    const transitionMs = duration;
    const transitionDelay = delay + index * interval;

    let style;
    if (hidden) {
        style = visible
            ? {
                opacity: 1,
                transform: 'none',
                transition: `opacity ${transitionMs}ms cubic-bezier(.22,1,.36,1) ${transitionDelay}ms, transform ${transitionMs}ms cubic-bezier(.22,1,.36,1) ${transitionDelay}ms`,
            }
            : {
                opacity: 0,
                transform: offset,
                transition: `opacity ${transitionMs}ms cubic-bezier(.22,1,.36,1) ${transitionDelay}ms, transform ${transitionMs}ms cubic-bezier(.22,1,.36,1) ${transitionDelay}ms`,
                willChange: 'opacity, transform',
            };
    }

    return (
        <Tag ref={ref} className={className} style={style} {...rest}>
            {children}
        </Tag>
    );
}
