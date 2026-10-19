import { useEffect, useState } from 'react';

/**
 * Floating back-to-top button — reference behavior: appears after 300px of
 * scroll, smooth-scrolls to the top on click (native smooth scrolling instead
 * of the reference's jQuery animate). Mounted once in LandingLayout.
 */
export default function ScrollTop() {
    const [show, setShow] = useState(false);

    useEffect(() => {
        let raf = null;
        const onScroll = () => {
            if (raf) return;
            raf = requestAnimationFrame(() => {
                setShow(window.scrollY > 300);
                raf = null;
            });
        };
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => {
            window.removeEventListener('scroll', onScroll);
            if (raf) cancelAnimationFrame(raf);
        };
    }, []);

    return (
        <button
            type="button"
            aria-label="Back to top"
            onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
            className={`fixed bottom-6 right-6 z-40 flex h-11 w-11 items-center justify-center rounded-full bg-[#5a8b38] text-white shadow-lg shadow-[#5a8b38]/30 transition-all duration-300 hover:bg-[#467235] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#5a8b38] focus-visible:ring-offset-2 ${
                show
                    ? 'translate-y-0 opacity-100 pointer-events-auto'
                    : 'translate-y-3 opacity-0 pointer-events-none'
            }`}
        >
            <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5" />
            </svg>
        </button>
    );
}
