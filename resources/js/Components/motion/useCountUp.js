import { useEffect, useRef, useState } from 'react';

/**
 * Animated count-up for the landing metrics band.
 *
 * `target` is a display string, not a number — admin-entered values like
 * "+380%", "2.4M", "92%" or "1,280" are parsed so the prefix/suffix survive
 * the animation. Non-numeric strings are returned as-is.
 *
 * Starts animating when `active` flips true (wire it to Reveal's onVisible).
 * Honors prefers-reduced-motion by showing the final value immediately.
 */
export function useCountUp(target, active, duration = 1400) {
    const [display, setDisplay] = useState(() => formatFallback(target));
    const rafRef = useRef(null);

    useEffect(() => {
        if (!active) return;

        const raw = String(target ?? '');
        const m = raw.match(/^([^\d\-]*)(-?[\d.,]+)(.*)$/);
        if (!m) {
            setDisplay(raw);
            return;
        }

        const [, prefix, numStr, suffix] = m;
        const end = parseFloat(numStr.replace(/,/g, ''));
        if (!Number.isFinite(end)) {
            setDisplay(raw);
            return;
        }

        const decimals = (numStr.split('.')[1] || '').length;
        const grouped = numStr.includes(',');

        if (typeof window !== 'undefined'
            && window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            setDisplay(prefix + numStr + suffix);
            return;
        }

        const start = performance.now();
        const tick = (now) => {
            const p = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - p, 3); // ease-out-cubic
            let val = (end * eased).toFixed(decimals);
            if (grouped) val = Number(val).toLocaleString('en-US', {
                minimumFractionDigits: decimals,
                maximumFractionDigits: decimals,
            });
            setDisplay(prefix + val + suffix);
            if (p < 1) rafRef.current = requestAnimationFrame(tick);
        };
        rafRef.current = requestAnimationFrame(tick);

        return () => {
            if (rafRef.current) cancelAnimationFrame(rafRef.current);
        };
    }, [active, target, duration]);

    return display;
}

// Before activation show the static value (safe for no-JS / initial paint).
function formatFallback(target) {
    return target == null ? '' : String(target);
}

export default useCountUp;
