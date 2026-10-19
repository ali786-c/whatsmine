/**
 * Infinite horizontal ticker (TrustedBy brand strip). Pure CSS: the track
 * holds two identical groups and translates -50% per loop, so the seam is
 * invisible. `speed` is the duration in seconds for one full cycle.
 *
 * Pauses on hover by default; fully disabled under prefers-reduced-motion
 * (see .wm-marquee rules in resources/css/app.css).
 */
export default function Marquee({
    speed = 35,
    pauseOnHover = true,
    className = '',
    children,
}) {
    return (
        <div
            className={`wm-marquee ${pauseOnHover ? 'wm-marquee--pause' : ''} ${className}`}
            aria-hidden="false"
        >
            <div className="wm-marquee__track" style={{ animationDuration: `${speed}s` }}>
                <div className="wm-marquee__group">{children}</div>
                <div className="wm-marquee__group" aria-hidden="true">{children}</div>
            </div>
        </div>
    );
}
