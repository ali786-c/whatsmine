import { useState } from 'react';
import Reveal from '@/Components/motion/Reveal';

/**
 * Animated FAQ accordion shared by the Welcome landing page and
 * Pages/marketing/Faq.jsx. Height animates via the CSS grid-rows 0fr → 1fr
 * trick (.wm-accordion in resources/css/app.css) so there is no JS measuring
 * and no content flash. Rows stagger in via Reveal.
 */
export default function FaqAccordion({ faqs, reveal = true, className = '' }) {
    const [open, setOpen] = useState(null);

    if (!faqs.length) return null;

    return (
        <div className={`space-y-2 ${className}`}>
            {faqs.map((faq, idx) => {
                const item = (
                    <div
                        key={reveal ? undefined : idx}
                        className="rounded-xl border border-[#E2EBD9] bg-white overflow-hidden"
                    >
                        <button
                            type="button"
                            className="w-full flex items-center justify-between px-5 py-4 text-left gap-4"
                            onClick={() => setOpen(open === idx ? null : idx)}
                            aria-expanded={open === idx}
                        >
                            <span className="font-medium text-[#0F1A0C] text-sm">{faq.q}</span>
                            <svg
                                className={`h-5 w-5 flex-shrink-0 text-[#7A8C6E] transition-transform duration-200 ${open === idx ? 'rotate-180' : ''}`}
                                fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"
                                aria-hidden="true"
                            >
                                <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                            </svg>
                        </button>
                        <div className={`wm-accordion ${open === idx ? 'is-open' : ''}`}>
                            <div>
                                <div className="px-5 pb-5 border-t border-[#E5EEDC] pt-4">
                                    <p className="text-sm text-[#4A5B44] leading-relaxed">{faq.a}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                );

                return reveal ? (
                    <Reveal
                        key={idx}
                        interval={80}
                        index={idx}
                        origin="bottom"
                        distance={30}
                        duration={700}
                    >
                        {item}
                    </Reveal>
                ) : (
                    item
                );
            })}
        </div>
    );
}
