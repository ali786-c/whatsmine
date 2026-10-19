import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';
import { BrandMark } from '@/Components/BrandIcons';
import Reveal from '@/Components/motion/Reveal';
import Marquee from '@/Components/motion/Marquee';
import { useCountUp } from '@/Components/motion/useCountUp';
import SectionShell from '@/Components/marketing/SectionShell';
import SectionHeading from '@/Components/marketing/SectionHeading';
import FaqAccordion from '@/Components/marketing/FaqAccordion';
import LiveChatMock from '@/Components/marketing/LiveChatMock';
import { useTranslation } from 'react-i18next';

// ─── Preloader ────────────────────────────────────────────────────────────────

function Preloader() {
    const [fading, setFading] = useState(false);
    const [gone, setGone] = useState(() => {
        // Skip entirely on repeat visits within the session (reference fades
        // out on window.load; we also cap the wait so slow assets never trap
        // the visitor behind the overlay) — and under prefers-reduced-motion.
        if (typeof window === 'undefined') return true;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return true;
        return sessionStorage.getItem('wm-preloaded') === '1';
    });

    useEffect(() => {
        if (gone) return;
        const finish = () => {
            setFading(true);
            try { sessionStorage.setItem('wm-preloaded', '1'); } catch { /* private mode */ }
            setTimeout(() => setGone(true), 500);
        };
        const done = document.readyState === 'complete';
        const timer = setTimeout(finish, 700);
        if (done) {
            // Already loaded — still let the loader flash briefly, but cap at 300ms.
            clearTimeout(timer);
            const quick = setTimeout(finish, 300);
            return () => clearTimeout(quick);
        }
        window.addEventListener('load', finish, { once: true });
        return () => {
            clearTimeout(timer);
            window.removeEventListener('load', finish);
        };
    }, [gone]);

    if (gone) return null;
    return (
        <div
            className={`fixed inset-0 z-[100] flex items-center justify-center transition-opacity duration-500 ${fading ? 'opacity-0' : 'opacity-100'}`}
            style={{ background: '#162610' }}
            aria-hidden="true"
        >
            <span className="wm-loader-text text-3xl font-extrabold tracking-[0.2em] uppercase select-none">
                WhatsMine
            </span>
        </div>
    );
}

// ─── Icon map ─────────────────────────────────────────────────────────────────

function FeatureIcon({ name, className = 'h-6 w-6' }) {
    const icons = {
        'cpu': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z" />
            </svg>
        ),
        'message-square': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
            </svg>
        ),
        'bar-chart-2': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" />
            </svg>
        ),
        'users': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
            </svg>
        ),
        'share-2': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M7.217 10.907a2.25 2.25 0 100 2.186m0-2.186c.18.324.283.696.283 1.093s-.103.77-.283 1.093m0-2.186l9.566-5.314m-9.566 7.5l9.566 5.314m0 0a2.25 2.25 0 103.935 2.186 2.25 2.25 0 00-3.935-2.186zm0-12.814a2.25 2.25 0 103.933-2.185 2.25 2.25 0 00-3.933 2.185z" />
            </svg>
        ),
        'shield-check': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
            </svg>
        ),
        'zap': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
            </svg>
        ),
        'star': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" />
            </svg>
        ),
        'layout': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
            </svg>
        ),
        'arrow-right': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
            </svg>
        ),
        'globe': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418" />
            </svg>
        ),
        'trending-up': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M2.25 18L9 11.25l4.306 4.307a11.95 11.95 0 015.814-5.519l2.74-1.22m0 0l-5.94-2.28m5.94 2.28l-2.28 5.941" />
            </svg>
        ),
        'check-circle': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
        ),
        'server': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 17.25v.75a2.25 2.25 0 01-2.25 2.25H4.5a2.25 2.25 0 01-2.25-2.25v-.75m16.5 0A2.25 2.25 0 0021.75 15V9.75A2.25 2.25 0 0019.5 7.5H4.5A2.25 2.25 0 002.25 9.75V15a2.25 2.25 0 002.25 2.25h15zM12 12.75h.008v.008H12v-.008z" />
            </svg>
        ),
    };
    return icons[name] || icons['zap'];
}

// ─── Channel Icon map ──────────────────────────────────────────────────────────

function ChannelIcon({ name, className = 'h-6 w-6' }) {
    const icons = {
        'whatsapp': (
            <svg className={className} fill="currentColor" viewBox="0 0 24 24">
                <path d="M.057 24l1.687-6.163a11.867 11.867 0 01-1.587-5.945C.16 5.335 5.495 0 12.05 0a11.817 11.817 0 018.413 3.488 11.824 11.824 0 013.48 8.414c-.003 6.557-5.338 11.892-11.893 11.892a11.9 11.9 0 01-5.688-1.448L.057 24zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884a9.86 9.86 0 001.51 5.26l-.999 3.648 3.978-1.607zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.247-.694.247-1.289.173-1.413z" />
            </svg>
        ),
        'messenger': (
            <svg className={className} fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 0C5.373 0 0 4.974 0 11.111c0 3.498 1.744 6.614 4.469 8.654V24l4.088-2.242c1.092.301 2.246.464 3.443.464 6.627 0 12-4.975 12-11.111C24 4.974 18.627 0 12 0zm1.191 14.963l-3.055-3.26-5.963 3.26L10.732 8.1l3.131 3.259L19.752 8.1l-6.561 6.863z" />
            </svg>
        ),
        'instagram': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.6} viewBox="0 0 24 24">
                <rect x="2" y="2" width="20" height="20" rx="5" ry="5" />
                <path strokeLinecap="round" strokeLinejoin="round" d="M16 11.37A4 4 0 1112.63 8 4 4 0 0116 11.37z" />
                <line x1="17.5" y1="6.5" x2="17.51" y2="6.5" strokeLinecap="round" />
            </svg>
        ),
        'sms': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M8 10.5h8M8 14h5m-9 6.5l1.5-3A8.38 8.38 0 013 11.5C3 6.81 7.03 3 12 3s9 3.81 9 8.5-4.03 8.5-9 8.5a9.7 9.7 0 01-3.2-.54L4 20.5z" />
            </svg>
        ),
        'email': (
            <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                <path strokeLinecap="round" strokeLinejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
            </svg>
        ),
    };
    return icons[name] || icons['email'];
}


// ─── Hero Section ─────────────────────────────────────────────────────────────

function HeroSection({ landing, canLogin, canRegister, auth }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('hero_enabled') !== '1') return null;

    const badgeText = s('hero_badge') || 'AI-Powered Customer Engagement Platform';
    const ctaPrimary = s('hero_cta_primary') || 'Start for free';
    const ctaSecondary = s('hero_cta_secondary') || 'Explore the platform';

    return (
        <section className="relative pt-12 pb-24 md:pt-16 md:pb-32 overflow-hidden bg-[#FAFDF7] text-[#0A0A0B]">
            {/* Background Grid & Radial Lighting */}
            <div className="absolute inset-0 bg-hero-grid-pattern pointer-events-none opacity-80 -z-10" />
            <div className="absolute top-24 left-1/2 -translate-x-1/2 w-[1200px] h-[750px] hero-radial-glow pointer-events-none -z-10" />

            {/* Centered Hero Content */}
            <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 text-center flex flex-col items-center">
                {/* Top Badge / Pill */}
                <Reveal as="div" origin="bottom" distance={15} duration={600}>
                    <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[#EDF4E4] border border-[#D5E3C7] text-xs font-semibold text-[#283F24] mb-6 shadow-sm hover:border-[#467235]/40 transition-colors">
                        <span className="text-[#FFBF00] font-bold text-sm">✦</span>
                        <span>{badgeText}</span>
                    </div>
                </Reveal>

                {/* Main Headline */}
                <Reveal as="div" origin="bottom" distance={20} duration={800} delay={80}>
                    <h1 className="font-space font-bold text-4xl sm:text-5xl md:text-6xl lg:text-[68px] leading-[1.08] tracking-[-0.03em] text-[#0A0A0B] max-w-4xl">
                        Every conversation.<br />
                        Automatically turned into{' '}
                        <span className="relative inline-block text-[#467235]">
                            growth.
                            <span className="absolute -bottom-1 left-0 right-0 h-[3.5px] bg-[#FFBF00] rounded-full opacity-80" />
                        </span>
                    </h1>
                </Reveal>

                {/* Subtitle */}
                <Reveal as="div" origin="bottom" distance={20} duration={800} delay={160}>
                    <p className="mt-6 text-base sm:text-lg md:text-xl text-[#3A4533] leading-relaxed max-w-2xl font-normal">
                        {s('hero_subtitle', 'Unify WhatsApp, Instagram, SMS and Email, automate customer journeys, deploy AI agents, and turn conversations into revenue — all from one powerful workspace.')}
                    </p>
                </Reveal>

                {/* CTA Buttons Row */}
                <Reveal as="div" className="mt-8 flex flex-col sm:flex-row items-center gap-3 sm:gap-4 w-full sm:w-auto" origin="bottom" distance={20} duration={800} delay={240}>
                    {auth?.user ? (
                        <Link
                            href={route('client.dashboard')}
                            className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 text-base font-semibold text-white bg-[#467235] hover:bg-[#395F2B] active:scale-[0.98] rounded-xl shadow-md shadow-[#467235]/30 border border-[#52843E] transition-all group"
                        >
                            <span>{t('welcome.goToDashboard')}</span>
                            <span className="group-hover:translate-x-1 transition-transform">→</span>
                        </Link>
                    ) : (
                        <>
                            {canRegister && (
                                <Link
                                    href={route('register')}
                                    className="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-7 py-3.5 text-base font-semibold text-white bg-[#467235] hover:bg-[#395F2B] active:scale-[0.98] rounded-xl shadow-md shadow-[#467235]/30 border border-[#52843E] transition-all group wm-shimmer"
                                >
                                    <span>{ctaPrimary}</span>
                                    <span className="group-hover:translate-x-1 transition-transform">→</span>
                                </Link>
                            )}
                            <Link
                                href={route('pricing')}
                                className="w-full sm:w-auto inline-flex items-center justify-center px-7 py-3.5 text-base font-semibold text-[#283F24] bg-white hover:bg-[#F4F8EC] hover:border-[#467235]/30 active:scale-[0.98] rounded-xl border border-[#D7E2C9] shadow-sm transition-all"
                            >
                                {ctaSecondary}
                            </Link>
                        </>
                    )}
                </Reveal>

                {/* Trust Line */}
                <Reveal as="div" origin="bottom" distance={15} duration={700} delay={320}>
                    <p className="mt-5 text-xs sm:text-sm text-[#5B6B52] flex flex-wrap items-center justify-center gap-2 font-medium tracking-tight">
                        <span>No credit card required</span>
                        <span className="text-[#8FA880] text-[10px]">●</span>
                        <span>Setup in minutes</span>
                        <span className="text-[#8FA880] text-[10px]">●</span>
                        <span>Built for growing teams</span>
                    </p>
                </Reveal>
            </div>
        </section>
    );
}

// ─── Section 2: Unified Omnichannel Inbox ──────────────────────────────────────

function UnifiedInboxSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('inbox_section_enabled') === '0') return null;

    return (
        <section className="relative py-24 md:py-32 px-4 sm:px-6 lg:px-8 border-t border-[#E5EEDC] bg-transparent overflow-hidden text-[#0A0A0B]">
            {/* Background Dot Pattern & Ambient Lighting */}
            <div className="absolute inset-0 bg-hero-grid-pattern opacity-70 pointer-events-none" />
            <div className="absolute inset-0 hero-radial-glow pointer-events-none" />

            {/* Section Top Header & Copy */}
            <div className="relative max-w-4xl mx-auto text-center z-10 mb-16 md:mb-20">
                {/* Eyebrow Pill Badge */}
                <Reveal as="div" origin="bottom" distance={15} duration={600}>
                    <div className="inline-flex items-center gap-2 px-3.5 py-1.5 rounded-full bg-[#F2F7EC] border border-[#D5E5C9] mb-5 shadow-sm">
                        <span className="inline-block w-2 h-2 rounded-full bg-[#467235] animate-pulse" />
                        <span className="font-space text-[11px] md:text-xs font-bold tracking-[0.14em] uppercase text-[#345927]">
                            ONE INBOX. EVERY CONVERSATION.
                        </span>
                    </div>
                </Reveal>

                {/* Main Headline */}
                <Reveal as="div" origin="bottom" distance={20} duration={800} delay={80}>
                    <h2 className="font-space text-3xl sm:text-4xl lg:text-5xl font-bold tracking-tight text-[#111827] leading-[1.18] sm:leading-[1.15] mb-6">
                        Stop switching between tabs.<br className="hidden sm:inline" />
                        Start managing every conversation in{' '}
                        <span className="relative inline-block text-[#467235] whitespace-nowrap">
                            one place.
                            <span className="absolute bottom-1 left-0 w-full h-[3px] bg-[#FFBF00] rounded-full opacity-90 -z-10" />
                        </span>
                    </h2>
                </Reveal>

                {/* Supporting Description */}
                <Reveal as="div" origin="bottom" distance={20} duration={800} delay={160}>
                    <p className="text-base sm:text-lg text-[#475569] leading-relaxed max-w-2xl mx-auto font-normal">
                        Bring WhatsApp, Instagram, SMS and Email into one real-time workspace. Give your team the context, tools and AI assistance they need to respond faster.
                    </p>
                </Reveal>

                {/* Channel Summary Badge Counter Bar */}
                <Reveal as="div" origin="bottom" distance={15} duration={700} delay={240}>
                    <div className="mt-8 inline-flex items-center gap-2 sm:gap-4 px-4 sm:px-5 py-2.5 rounded-2xl bg-white/90 backdrop-blur-md border border-[#E2E8F0] shadow-sm">
                        <span className="text-xs font-medium text-slate-500 mr-1 hidden sm:inline">Active channels:</span>

                        {/* WhatsApp */}
                        <div className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[#25D366]/10 border border-[#25D366]/20">
                            <svg className="w-3.5 h-3.5 text-[#25D366]" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
                            </svg>
                            <span className="text-xs font-semibold text-slate-800">WhatsApp</span>
                            <span className="text-[11px] font-bold px-1.5 py-0.2 rounded-full bg-[#25D366] text-white">24</span>
                        </div>

                        {/* Instagram */}
                        <div className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[#E1306C]/10 border border-[#E1306C]/20">
                            <svg className="w-3.5 h-3.5 text-[#E1306C]" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" />
                            </svg>
                            <span className="text-xs font-semibold text-slate-800">Instagram</span>
                            <span className="text-[11px] font-bold px-1.5 py-0.2 rounded-full bg-[#E1306C] text-white">8</span>
                        </div>

                        {/* SMS */}
                        <div className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[#0284C7]/10 border border-[#0284C7]/20">
                            <svg className="w-3.5 h-3.5 text-[#0284C7]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                            <span className="text-xs font-semibold text-slate-800">SMS</span>
                            <span className="text-[11px] font-bold px-1.5 py-0.2 rounded-full bg-[#0284C7] text-white">5</span>
                        </div>

                        {/* Email */}
                        <div className="flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-[#EA4335]/10 border border-[#EA4335]/20">
                            <svg className="w-3.5 h-3.5 text-[#EA4335]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                            <span className="text-xs font-semibold text-slate-800">Email</span>
                            <span className="text-[11px] font-bold px-1.5 py-0.2 rounded-full bg-[#EA4335] text-white">3</span>
                        </div>
                    </div>
                </Reveal>
            </div>

            {/* MAIN PRODUCT SHOWCASE CONTAINER */}
            <div className="relative max-w-6xl mx-auto z-10">
                {/* FLOATING ELEMENT 1: Top-Right Overlapping Card */}
                <div className="absolute -top-7 -right-3 md:-right-6 z-30 hidden sm:flex items-center gap-3 px-4 py-3 rounded-2xl bg-white/95 backdrop-blur-md border border-[#E2E8F0] shadow-lg transition-transform duration-300 hover:-translate-y-1 animate-float-1">
                    <div className="w-9 h-9 rounded-xl bg-[#F0F7EC] text-[#467235] border border-[#D5E6C8] flex items-center justify-center shrink-0">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <div className="text-left">
                        <div className="flex items-center gap-2">
                            <span className="text-xs font-bold text-[#1E293B]">AI resolved</span>
                            <span className="text-[10px] font-semibold text-[#467235] bg-[#EAF5E5] px-1.5 py-0.5 rounded">99.4% accuracy</span>
                        </div>
                        <p className="text-[11px] text-slate-500 font-medium">8 conversations handled automatically</p>
                    </div>
                </div>

                {/* FLOATING ELEMENT 2: Bottom-Left Overlapping Card */}
                <div className="absolute -bottom-6 -left-3 md:-left-6 z-30 hidden sm:flex items-center gap-3 px-4 py-3 rounded-2xl bg-white/95 backdrop-blur-md border border-[#E2E8F0] shadow-lg transition-transform duration-300 hover:translate-y-1 animate-float-2">
                    <div className="relative w-9 h-9 rounded-xl bg-[#25D366]/10 text-[#25D366] border border-[#25D366]/20 flex items-center justify-center shrink-0">
                        <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
                        </svg>
                        <span className="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-[#25D366] border-2 border-white animate-ping" />
                        <span className="absolute -top-1 -right-1 w-2.5 h-2.5 rounded-full bg-[#25D366] border-2 border-white" />
                    </div>
                    <div className="text-left">
                        <div className="flex items-center gap-1.5">
                            <span className="text-xs font-bold text-[#1E293B]">New WhatsApp message</span>
                        </div>
                        <p className="text-[11px] text-slate-500 font-medium">+1 conversation queued · Auto-routed</p>
                    </div>
                </div>

                {/* FLOATING ELEMENT 3: Center-Bottom Response Time Card */}
                <div className="absolute -bottom-7 right-12 md:right-28 z-30 hidden sm:flex items-center gap-3 px-4 py-2.5 rounded-2xl bg-white/95 backdrop-blur-md border border-[#E2E8F0] shadow-lg animate-float-3">
                    <div className="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 border border-amber-200/60 flex items-center justify-center shrink-0">
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                        </svg>
                    </div>
                    <div className="text-left">
                        <div className="text-[10px] font-semibold text-slate-400 uppercase tracking-wider">Median Response Time</div>
                        <div className="flex items-center gap-2">
                            <span className="font-space text-sm font-bold text-[#1E293B]">38 sec</span>
                            <span className="text-[10px] font-bold text-[#467235] bg-[#EAF5E5] px-1.5 py-0.5 rounded">94% faster</span>
                        </div>
                    </div>
                </div>

                {/* MAIN DESKTOP DASHBOARD MOCKUP */}
                <Reveal as="div" origin="bottom" distance={30} duration={900} delay={200}>
                    <div className="w-full bg-[#FCFCFD] rounded-2xl md:rounded-3xl border border-[#DDE5D6] shadow-[0_25px_60px_-15px_rgba(40,63,36,0.12)] overflow-hidden">
                        {/* BROWSER WINDOW HEADER */}
                        <div className="bg-[#F8FAF6] border-b border-[#E3EADF] px-4 py-3 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <span className="w-3 h-3 rounded-full bg-[#FF5F56] border border-[#E0443E]/30 inline-block" />
                                <span className="w-3 h-3 rounded-full bg-[#FFBD2E] border border-[#DEA123]/30 inline-block" />
                                <span className="w-3 h-3 rounded-full bg-[#27C93F] border border-[#1AAB29]/30 inline-block" />
                                <span className="ml-3 text-[11px] font-medium text-slate-400 font-mono hidden md:inline">https://app.whatsmine.com/inbox/unified</span>
                            </div>

                            {/* Workspace Switcher */}
                            <div className="flex items-center gap-2 bg-white px-3 py-1 rounded-lg border border-[#E2E8F0] text-xs font-medium text-slate-700 shadow-xs">
                                <span className="w-4 h-4 rounded bg-[#283F24] text-white flex items-center justify-center text-[10px] font-bold font-space">W</span>
                                <span className="font-semibold text-slate-800">Acme Workspace</span>
                                <svg className="w-3.5 h-3.5 text-slate-400 ml-1" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>

                            <div className="flex items-center gap-2">
                                <span className="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full bg-[#EBF5E5] text-[#3B662A] text-[11px] font-semibold border border-[#CCE3C1]">
                                    <span className="w-1.5 h-1.5 rounded-full bg-[#467235]" />
                                    Live Sync
                                </span>
                            </div>
                        </div>

                        {/* DASHBOARD BODY: 4-COLUMN INBOX LAYOUT */}
                        <div className="flex h-[720px] md:h-[680px] bg-white divide-x divide-slate-100 overflow-hidden text-left">
                            {/* PANEL 1: SIDEBAR */}
                            <aside className="w-48 lg:w-52 shrink-0 bg-[#FBFDF9] border-r border-[#E9EFE6] flex flex-col justify-between overflow-y-auto p-3 text-xs select-none">
                                <div className="space-y-4">
                                    <div className="flex items-center justify-between pb-1">
                                        <div className="flex items-center gap-2">
                                            <svg className="w-4 h-4 text-slate-700" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                            </svg>
                                            <span className="font-space font-bold text-sm text-slate-900">Inbox</span>
                                        </div>
                                        <button className="w-6 h-6 rounded-md bg-[#467235] text-white flex items-center justify-center font-bold text-sm hover:bg-[#385d2a] shadow-2xs transition-colors">
                                            +
                                        </button>
                                    </div>

                                    <div>
                                        <span className="text-[10px] font-bold text-slate-400 tracking-wider uppercase block mb-1.5">VIEWS</span>
                                        <nav className="space-y-0.5">
                                            <a href="#all" className="flex items-center gap-2 px-2 py-1.5 rounded-lg bg-[#EAF5E5] text-[#2F5222] font-semibold">
                                                <svg className="w-3.5 h-3.5 text-[#467235]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                                                </svg>
                                                <span>All</span>
                                            </a>
                                            <a href="#mine" className="flex items-center gap-2 px-2 py-1.5 rounded-lg text-slate-600 hover:bg-slate-100/70 font-medium">
                                                <svg className="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                                </svg>
                                                <span>Mine</span>
                                            </a>
                                            <a href="#unassigned" className="flex items-center gap-2 px-2 py-1.5 rounded-lg text-slate-600 hover:bg-slate-100/70 font-medium">
                                                <svg className="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                                </svg>
                                                <span>Unassigned</span>
                                            </a>
                                            <a href="#resolved" className="flex items-center gap-2 px-2 py-1.5 rounded-lg text-slate-600 hover:bg-slate-100/70 font-medium">
                                                <svg className="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span>Resolved</span>
                                            </a>
                                            <a href="#snoozed" className="flex items-center gap-2 px-2 py-1.5 rounded-lg text-slate-600 hover:bg-slate-100/70 font-medium">
                                                <svg className="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span>Snoozed</span>
                                            </a>
                                        </nav>
                                    </div>

                                    <div>
                                        <span className="text-[10px] font-bold text-slate-400 tracking-wider uppercase block mb-1.5">CHANNELS</span>
                                        <nav className="space-y-0.5 text-slate-700">
                                            <div className="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-100/60 cursor-pointer">
                                                <span className="w-2 h-2 rounded-full bg-[#25D366]" />
                                                <span>WhatsApp</span>
                                            </div>
                                            <div className="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-100/60 cursor-pointer">
                                                <span className="w-2 h-2 rounded-full bg-[#128C7E]" />
                                                <span>WhatsApp Web</span>
                                            </div>
                                            <div className="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-100/60 cursor-pointer">
                                                <span className="w-2 h-2 rounded-full bg-[#E1306C]" />
                                                <span>Instagram</span>
                                            </div>
                                            <div className="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-100/60 cursor-pointer">
                                                <span className="w-2 h-2 rounded-full bg-[#0284C7]" />
                                                <span>Messenger</span>
                                            </div>
                                            <div className="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-100/60 cursor-pointer">
                                                <span className="w-2 h-2 rounded-full bg-[#0369A1]" />
                                                <span>SMS</span>
                                            </div>
                                            <div className="flex items-center gap-2 px-2 py-1 rounded hover:bg-slate-100/60 cursor-pointer">
                                                <span className="w-2 h-2 rounded-full bg-[#EA4335]" />
                                                <span>Email</span>
                                            </div>
                                        </nav>
                                    </div>

                                    <div>
                                        <span className="text-[10px] font-bold text-slate-400 tracking-wider uppercase block mb-1.5">NUMBERS</span>
                                        <nav className="space-y-1 text-[11px] text-slate-600">
                                            <div className="flex items-center gap-1.5 truncate px-2 py-0.5 hover:bg-slate-100/60 rounded cursor-pointer">
                                                <span className="text-[#E1306C] text-xs font-mono">@</span>
                                                <span className="truncate">whatsmine_team</span>
                                            </div>
                                            <div className="flex items-center gap-1.5 truncate px-2 py-0.5 hover:bg-slate-100/60 rounded cursor-pointer">
                                                <span className="w-1.5 h-1.5 rounded-full bg-[#0284C7]" />
                                                <span className="truncate">support@whatsmine.com</span>
                                            </div>
                                            <div className="flex items-center gap-1.5 truncate px-2 py-0.5 hover:bg-slate-100/60 rounded cursor-pointer">
                                                <span className="w-1.5 h-1.5 rounded-full bg-[#25D366]" />
                                                <span className="truncate">+1 (555) 019-2831 (US Sales)</span>
                                            </div>
                                            <div className="flex items-center gap-1.5 truncate px-2 py-0.5 hover:bg-slate-100/60 rounded cursor-pointer">
                                                <span className="w-1.5 h-1.5 rounded-full bg-[#25D366]" />
                                                <span className="truncate">+44 7700 900123 (UK)</span>
                                            </div>
                                        </nav>
                                    </div>

                                    <div>
                                        <span className="text-[10px] font-bold text-slate-400 tracking-wider uppercase block mb-1.5">LABELS</span>
                                        <div className="flex items-center gap-2 px-2 py-1 rounded-md text-slate-700 hover:bg-slate-100/60 cursor-pointer">
                                            <span className="w-2 h-2 rounded-full bg-[#FFBF00]" />
                                            <span className="text-xs font-medium">Waiting for you</span>
                                        </div>
                                    </div>
                                </div>
                            </aside>

                            {/* PANEL 2: CONVERSATION LIST FEED */}
                            <div className="w-64 lg:w-72 shrink-0 bg-white border-r border-[#E9EFE6] flex flex-col overflow-hidden select-none">
                                <div className="p-3 border-b border-slate-100 space-y-2">
                                    <div className="flex items-center justify-between text-xs text-slate-500 font-medium">
                                        <span className="font-semibold text-slate-700">All</span>
                                        <div className="flex items-center gap-1.5 text-slate-400 text-[11px]">
                                            <span>13</span>
                                            <svg className="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.536 8.464a5 5 0 010 7.072m2.828-9.9a9 9 0 010 12.728M4 10h4l5-4v12l-5-4H4v-4z" />
                                            </svg>
                                            <svg className="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                            </svg>
                                        </div>
                                    </div>
                                    <div className="relative">
                                        <svg className="w-3.5 h-3.5 absolute left-2.5 top-2.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                        <input type="text" placeholder="Search conversations..." className="w-full pl-8 pr-2.5 py-1.5 text-xs bg-slate-50 border border-slate-200 rounded-lg text-slate-700 placeholder-slate-400 focus:outline-none" readOnly />
                                    </div>
                                </div>

                                <div className="flex-1 overflow-y-auto divide-y divide-slate-100 text-xs custom-scroll">
                                    {/* Card 1: Sophie Taylor */}
                                    <div className="p-2.5 bg-[#F4F8F0] border-l-4 border-[#467235] cursor-pointer">
                                        <div className="flex items-start gap-2">
                                            <div className="relative shrink-0">
                                                <div className="w-8 h-8 rounded-full bg-[#E5EFE1] text-[#345927] font-semibold text-xs flex items-center justify-center font-space">
                                                    ST
                                                </div>
                                                <span className="w-2.5 h-2.5 rounded-full bg-[#25D366] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-bold text-slate-900 truncate text-[11px]">Sophie Taylor 💬</span>
                                                    <span className="text-[10px] text-slate-400">03:04 PM</span>
                                                </div>
                                                <p className="text-[11px] text-slate-600 truncate mt-0.5">Thank you for the warm message...</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Card 2: UK Support Line */}
                                    <div className="p-2.5 hover:bg-slate-50 cursor-pointer border-l-4 border-transparent">
                                        <div className="flex items-start gap-2">
                                            <div className="relative shrink-0">
                                                <div className="w-8 h-8 rounded-full bg-slate-100 text-slate-600 font-semibold text-xs flex items-center justify-center font-mono">
                                                    +44
                                                </div>
                                                <span className="w-2.5 h-2.5 rounded-full bg-[#25D366] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-semibold text-slate-800 truncate text-[11px]">+44 7700 900123</span>
                                                    <span className="text-[10px] text-slate-400">12:36 PM</span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 truncate mt-0.5">It looks like you might have sent...</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Card 3: Support Line */}
                                    <div className="p-2.5 hover:bg-slate-50 cursor-pointer border-l-4 border-transparent">
                                        <div className="flex items-start gap-2">
                                            <div className="relative shrink-0">
                                                <div className="w-8 h-8 rounded-full bg-[#E5EFE1] text-[#345927] font-semibold text-xs flex items-center justify-center font-space">
                                                    S
                                                </div>
                                                <span className="w-2.5 h-2.5 rounded-full bg-[#25D366] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-semibold text-slate-800 truncate text-[11px]">Support Line</span>
                                                    <div className="flex items-center gap-1">
                                                        <span className="text-[10px] text-slate-400">11:59 AM</span>
                                                        <span className="w-4 h-4 rounded-full bg-[#283F24] text-white text-[9px] font-bold flex items-center justify-center">1</span>
                                                    </div>
                                                </div>
                                                <p className="text-[11px] text-slate-500 truncate mt-0.5">Hi, can you verify my ticket?</p>
                                                <div className="mt-1">
                                                    <span className="inline-block px-1.5 py-0.2 rounded text-[9px] font-medium bg-[#FFF78D]/40 text-[#785900] border border-[#FFBF00]/30">Waiting for you</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Card 4: Sarah Malik */}
                                    <div className="p-2.5 hover:bg-slate-50 cursor-pointer border-l-4 border-transparent">
                                        <div className="flex items-start gap-2">
                                            <div className="relative shrink-0">
                                                <div className="w-8 h-8 rounded-full bg-[#FCE8F0] text-[#9D174D] font-semibold text-xs flex items-center justify-center font-space">
                                                    SM
                                                </div>
                                                <span className="w-2.5 h-2.5 rounded-full bg-[#E1306C] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-semibold text-slate-800 truncate text-[11px]">Sarah Malik</span>
                                                    <span className="text-[10px] text-slate-400">12:26 PM</span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 truncate mt-0.5">Thanks for your comment! Here is the catalog link...</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Card 5: David Miller */}
                                    <div className="p-2.5 hover:bg-slate-50 cursor-pointer border-l-4 border-transparent">
                                        <div className="flex items-start gap-2">
                                            <div className="relative shrink-0">
                                                <div className="w-8 h-8 rounded-full bg-[#EAF5E5] text-[#345927] font-semibold text-xs flex items-center justify-center font-space">
                                                    DM
                                                </div>
                                                <span className="w-2.5 h-2.5 rounded-full bg-[#25D366] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-semibold text-slate-800 truncate text-[11px]">David Miller</span>
                                                    <span className="text-[10px] text-slate-400">12:22 PM</span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 truncate mt-0.5">Hi there, quick delivery question regarding my order...</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Card 6: +1 415 555 0198 */}
                                    <div className="p-2.5 hover:bg-slate-50 cursor-pointer border-l-4 border-transparent">
                                        <div className="flex items-start gap-2">
                                            <div className="relative shrink-0">
                                                <div className="w-8 h-8 rounded-full bg-slate-100 text-slate-600 font-semibold text-xs flex items-center justify-center font-mono">
                                                    +1
                                                </div>
                                                <span className="w-2.5 h-2.5 rounded-full bg-[#25D366] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-semibold text-slate-800 truncate text-[11px]">+1 (415) 555-0198</span>
                                                    <span className="text-[10px] text-slate-400">10:16 PM</span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 truncate mt-0.5">We understand. Your order has been dispatched...</p>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Card 7: Alex Turner */}
                                    <div className="p-2.5 hover:bg-slate-50 cursor-pointer border-l-4 border-transparent">
                                        <div className="flex items-start gap-2">
                                            <div className="relative shrink-0">
                                                <div className="w-8 h-8 rounded-full bg-blue-50 text-blue-700 font-semibold text-xs flex items-center justify-center font-space">
                                                    AT
                                                </div>
                                                <span className="w-2.5 h-2.5 rounded-full bg-[#0284C7] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                            </div>
                                            <div className="flex-1 min-w-0">
                                                <div className="flex items-center justify-between">
                                                    <span className="font-semibold text-slate-800 truncate text-[11px]">Alex Turner</span>
                                                    <span className="text-[10px] text-slate-400">06:40 PM</span>
                                                </div>
                                                <p className="text-[11px] text-slate-500 truncate mt-0.5">Yes, can you help me with integration?</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* PANEL 3: CHAT THREAD */}
                            <div className="flex-1 flex flex-col bg-white overflow-hidden">
                                <div className="px-4 py-2.5 border-b border-slate-100 flex items-center justify-between bg-white shrink-0">
                                    <div className="flex items-center gap-2.5">
                                        <div className="relative">
                                            <div className="w-8 h-8 rounded-full bg-[#E5EFE1] text-[#345927] font-bold text-xs flex items-center justify-center font-space">
                                                ST
                                            </div>
                                            <span className="w-2.5 h-2.5 rounded-full bg-[#25D366] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                        </div>
                                        <div>
                                            <div className="flex items-center gap-1.5">
                                                <span className="font-bold text-slate-900 text-xs">Sophie Taylor</span>
                                                <span className="text-xs">💬</span>
                                            </div>
                                            <div className="flex items-center gap-1.5 text-[11px] text-slate-400 font-medium">
                                                <span className="text-[#25D366]">WhatsApp</span>
                                                <span>·</span>
                                                <span>+1 (555) 019-2831</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-2">
                                        <button className="p-1 text-slate-400 hover:text-slate-600">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                            </svg>
                                        </button>
                                        <button className="flex items-center gap-1 px-2.5 py-1 rounded-lg border border-slate-200 text-slate-700 text-xs font-medium hover:bg-slate-50 transition-colors">
                                            <svg className="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                                            </svg>
                                            <span>Assign</span>
                                            <svg className="w-3 h-3 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                        <button className="flex items-center gap-1 px-2.5 py-1 rounded-lg bg-[#EAF5E5] text-[#2F5222] border border-[#D1E5C6] text-xs font-semibold hover:bg-[#dff0d8] transition-colors">
                                            <span>Open</span>
                                            <svg className="w-3 h-3 text-[#345927]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M19 9l-7 7-7-7" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                <div className="flex items-center gap-6 px-4 border-b border-slate-100 text-xs font-medium text-slate-500 bg-white shrink-0">
                                    <button className="py-2 text-[#467235] font-bold border-b-2 border-[#467235] flex items-center gap-1.5">
                                        <span>Messages</span>
                                    </button>
                                    <button className="py-2 hover:text-slate-800 transition-colors flex items-center gap-1.5">
                                        <svg className="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                        </svg>
                                        <span>Media</span>
                                    </button>
                                    <button className="py-2 hover:text-slate-800 transition-colors flex items-center gap-1.5">
                                        <svg className="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                        </svg>
                                        <span>Notes</span>
                                    </button>
                                    <button className="py-2 hover:text-slate-800 transition-colors flex items-center gap-1.5">
                                        <svg className="w-3.5 h-3.5 text-slate-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" />
                                        </svg>
                                        <span>Orders</span>
                                    </button>
                                </div>

                                <div className="flex-1 p-4 overflow-y-auto space-y-3 bg-[#FCFCFD] text-xs custom-scroll">
                                    {/* Bubble 1 */}
                                    <div className="flex items-start gap-2 max-w-sm">
                                        <div className="w-24 h-28 rounded-lg overflow-hidden border border-slate-200 shadow-2xs bg-slate-100 flex flex-col justify-end p-2 relative">
                                            <div className="absolute inset-0 bg-gradient-to-t from-black/50 to-transparent flex items-end p-2">
                                                <span className="text-[10px] text-white font-medium">Order receipt</span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Bubble 2 */}
                                    <div className="flex flex-col items-end space-y-1">
                                        <div className="text-[10px] text-slate-500 font-medium flex items-center gap-1 mr-1">
                                            <span>😊 reacted</span>
                                        </div>
                                        <div className="bg-[#2e591f] text-white px-3.5 py-2 rounded-xl rounded-tr-none max-w-xs shadow-xs text-left">
                                            <p>Thanks for reaching out! Let us check your order status right away.</p>
                                            <div className="flex justify-end items-center gap-1 text-[9px] text-[#c2f5a9] mt-1">
                                                <span>10:22 PM</span>
                                                <span>✓✓</span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Bubble 3 */}
                                    <div className="flex flex-col items-end">
                                        <div className="bg-[#2e591f] text-white px-3.5 py-2.5 rounded-xl rounded-tr-none max-w-md shadow-xs text-left leading-relaxed">
                                            <p>Thank you for sharing your order reference #10291. I'm here to assist you right away. What information do you need about your delivery or package? 😊</p>
                                            <div className="flex justify-end items-center gap-1 text-[9px] text-[#c2f5a9] mt-1">
                                                <span>11:57 AM</span>
                                                <span>✓✓</span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Bubble 4 */}
                                    <div className="flex flex-col items-end">
                                        <div className="bg-[#2e591f] text-white px-3 py-1.5 rounded-xl rounded-tr-none max-w-sm shadow-xs text-left">
                                            <p>Following up regarding our previous conversation.</p>
                                            <div className="flex justify-end items-center gap-1 text-[9px] text-[#c2f5a9] mt-1">
                                                <span>03:01 PM</span>
                                                <span>✓✓</span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Bubble 5 */}
                                    <div className="flex flex-col items-end">
                                        <div className="bg-[#2e591f] text-white px-3 py-1.5 rounded-xl rounded-tr-none max-w-sm shadow-xs text-left">
                                            <p>Is there anything else I can assist you with?</p>
                                            <div className="flex justify-end items-center gap-1 text-[9px] text-[#c2f5a9] mt-1">
                                                <span>03:02 PM</span>
                                                <span>✓✓</span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* Bubble 6 */}
                                    <div className="flex items-start gap-2 max-w-xs">
                                        <div className="w-20 h-20 rounded-lg overflow-hidden border border-slate-200 shadow-2xs bg-slate-200 flex items-center justify-center text-slate-500 font-bold text-xs">
                                            📦 Verified
                                        </div>
                                    </div>

                                    {/* Bubble 7 */}
                                    <div className="flex flex-col items-end">
                                        <div className="bg-[#2e591f] text-white px-3.5 py-2.5 rounded-xl rounded-tr-none max-w-md shadow-xs text-left leading-relaxed">
                                            <p>Thank you for the warm message! Your order is scheduled for delivery today by 5:00 PM. How else can I help? 😊</p>
                                            <div className="flex justify-end items-center gap-1 text-[9px] text-[#c2f5a9] mt-1">
                                                <span>03:04 PM</span>
                                                <span>✓✓</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="p-3 bg-white border-t border-slate-100 shrink-0 space-y-2">
                                    <div className="flex items-center gap-3 text-slate-400 text-xs px-1">
                                        <button className="hover:text-[#467235] text-[#FFBF00] transition-colors" title="AI Assist">
                                            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                                <path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8z" />
                                            </svg>
                                        </button>
                                        <button className="hover:text-slate-600 transition-colors" title="Emoji">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M14.828 14.828a4 4 0 01-5.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        </button>
                                        <button className="hover:text-slate-600 transition-colors" title="Attach file">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                            </svg>
                                        </button>
                                        <button className="hover:text-slate-600 transition-colors" title="Media">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                            </svg>
                                        </button>
                                        <button className="hover:text-slate-600 transition-colors" title="Notes">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                            </svg>
                                        </button>
                                        <button className="hover:text-slate-600 transition-colors" title="Templates">
                                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M4 6h16M4 12h16m-7 6h7" />
                                            </svg>
                                        </button>
                                    </div>

                                    <div className="flex items-center gap-2 bg-slate-50 border border-slate-200 rounded-xl px-3 py-1.5 focus-within:border-[#467235] focus-within:bg-white transition-all">
                                        <svg className="w-4 h-4 text-slate-400 shrink-0" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z" />
                                        </svg>
                                        <input type="text" placeholder="Type a message... or /shortcut for canned reply" className="flex-1 bg-transparent text-xs text-slate-800 placeholder-slate-400 focus:outline-none" readOnly />
                                        <button className="w-7 h-7 rounded-lg bg-[#467235] text-white flex items-center justify-center hover:bg-[#385d2a] shadow-xs shrink-0 transition-colors">
                                            <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            {/* PANEL 4: RIGHT DETAIL SIDEBAR */}
                            <div className="w-56 lg:w-64 shrink-0 bg-[#FBFDF9] p-3.5 border-l border-slate-100 flex flex-col justify-between overflow-y-auto text-xs">
                                <div className="space-y-4">
                                    <div className="flex items-center gap-2.5 pb-2 border-b border-slate-100">
                                        <div className="w-10 h-10 rounded-full bg-[#E5EFE1] text-[#345927] font-space font-bold text-sm flex items-center justify-center shrink-0">
                                            ST
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center gap-1">
                                                <span className="font-bold text-slate-900 truncate">Sophie Taylor</span>
                                                <span className="text-[11px]">💬</span>
                                            </div>
                                            <p className="text-[10px] text-slate-400 font-mono">+1 (555) 019-2831</p>
                                        </div>
                                    </div>

                                    <a href="#profile" className="text-[11px] font-semibold text-[#467235] hover:underline flex items-center gap-1">
                                        <span>View full profile</span>
                                        <span>→</span>
                                    </a>

                                    <div className="space-y-2">
                                        <span className="text-[10px] font-bold text-slate-400 tracking-wider uppercase block">CONVERSATION</span>
                                        <div className="space-y-1.5 text-xs">
                                            <div className="flex items-center justify-between">
                                                <span className="text-slate-500">Status</span>
                                                <span className="px-2 py-0.5 rounded-full bg-[#EAF5E5] text-[#2F5222] font-semibold text-[10px]">Open</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-slate-500">Agent</span>
                                                <span className="text-slate-400 font-medium">—</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <span className="text-slate-500">Messages</span>
                                                <span className="font-bold text-slate-800">138</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="space-y-1.5">
                                        <div className="flex items-center justify-between">
                                            <span className="text-[10px] font-bold text-slate-400 tracking-wider uppercase">TAGS</span>
                                            <button className="text-[10px] text-slate-400 hover:text-slate-600">Manage</button>
                                        </div>
                                        <div>
                                            <span className="inline-block px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-600 border border-amber-200/60 text-[10px] font-semibold">
                                                Waiting for you
                                            </span>
                                        </div>
                                    </div>

                                    <div className="space-y-1.5">
                                        <span className="text-[10px] font-bold text-slate-400 tracking-wider uppercase block">OPT-INS</span>
                                        <div className="space-y-1.5 text-xs">
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-1.5 text-slate-600">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-[#25D366]" />
                                                    <span>WhatsApp</span>
                                                </div>
                                                <span className="font-semibold text-[#467235] text-[11px]">Yes</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-1.5 text-slate-600">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-[#0284C7]" />
                                                    <span>SMS</span>
                                                </div>
                                                <span className="font-medium text-slate-400 text-[11px]">No</span>
                                            </div>
                                            <div className="flex items-center justify-between">
                                                <div className="flex items-center gap-1.5 text-slate-600">
                                                    <span className="w-1.5 h-1.5 rounded-full bg-[#EA4335]" />
                                                    <span>Email</span>
                                                </div>
                                                <span className="font-semibold text-[#467235] text-[11px]">Yes</span>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="space-y-2 pt-2 border-t border-slate-100">
                                        <span className="text-[10px] font-bold text-slate-400 tracking-wider uppercase block">AI HANDOVER</span>
                                        <div className="flex items-center justify-between">
                                            <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-[#EAF5E5] text-[#2F5222] font-semibold text-[11px]">
                                                <span>🤖</span> Bot
                                            </span>
                                            <button className="text-xs font-semibold text-amber-600 hover:text-amber-700 transition-colors">
                                                Take over
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </Reveal>
            </div>

            {/* BOTTOM ROW: 4 CHANNEL LABELS WITH ICONS */}
            <div className="relative max-w-4xl mx-auto mt-16 md:mt-20 z-10">
                <div className="flex flex-wrap items-center justify-center gap-6 sm:gap-10 md:gap-14 text-slate-500">
                    <div className="flex items-center gap-2.5 transition-colors hover:text-slate-800">
                        <div className="w-8 h-8 rounded-full bg-[#25D366]/10 text-[#25D366] flex items-center justify-center">
                            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
                            </svg>
                        </div>
                        <span className="font-space font-semibold text-xs text-slate-700">WhatsApp</span>
                    </div>

                    <div className="flex items-center gap-2.5 transition-colors hover:text-slate-800">
                        <div className="w-8 h-8 rounded-full bg-[#E1306C]/10 text-[#E1306C] flex items-center justify-center">
                            <svg className="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" />
                            </svg>
                        </div>
                        <span className="font-space font-semibold text-xs text-slate-700">Instagram</span>
                    </div>

                    <div className="flex items-center gap-2.5 transition-colors hover:text-slate-800">
                        <div className="w-8 h-8 rounded-full bg-[#0284C7]/10 text-[#0284C7] flex items-center justify-center">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                            </svg>
                        </div>
                        <span className="font-space font-semibold text-xs text-slate-700">SMS</span>
                    </div>

                    <div className="flex items-center gap-2.5 transition-colors hover:text-slate-800">
                        <div className="w-8 h-8 rounded-full bg-[#EA4335]/10 text-[#EA4335] flex items-center justify-center">
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <span className="font-space font-semibold text-xs text-slate-700">Email</span>
                    </div>
                </div>
            </div>
        </section>
    );
}

// ─── Trusted By Section ───────────────────────────────────────────────────────

function TrustedBySection({ landing }) {
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    if (s('stats_enabled') !== '1') return null;
    const brands = [1, 2, 3, 4, 5, 6].map(function (i) { return s('stats_' + i + '_label'); }).filter(Boolean);
    if (!brands.length) return null;
    const heading = s('stats_heading', 'Trusted by thousands of businesses across the world');
    return (
        <SectionShell variant="light" className="border-b border-[#E5EEDC] py-12">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                {heading ? <p className="mb-7 text-center text-xs font-bold uppercase tracking-[0.18em] text-[#7A8C6E]">{heading}</p> : null}
                <Marquee speed={35}>
                    {brands.map(function (brand, idx) {
                        return (
                            <span key={idx} className="mx-2 inline-flex select-none items-center gap-2 whitespace-nowrap rounded-full border border-[#E2EBD9] bg-white px-5 py-2.5 text-sm font-bold text-[#3F5238] shadow-soft-sm transition-colors hover:border-brand-400/50 hover:text-brand-700">
                                <span className="inline-block h-1.5 w-1.5 rounded-full bg-brand-500" />
                                {brand}
                            </span>
                        );
                    })}
                </Marquee>
            </div>
        </SectionShell>
    );
}

// ─── Problem / Solution Section ──────────────────────────────────────────────

function ProblemSolutionSection({ landing }) {
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    if (s('problems_enabled') !== '1') return null;
    const problems = [1, 2, 3, 4].map(function (i) { return s('problem_' + i); }).filter(Boolean);
    const solutions = [1, 2, 3, 4].map(function (i) { return s('solution_' + i); }).filter(Boolean);
    const problemTitle = s('problems_title', 'The Problem');
    const solutionTitle = s('solution_title', 'The Solution');
    const solutionDesc = s('solution_desc', '');
    if (!problems.length && !solutions.length) return null;
    return (
        <SectionShell variant="light" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div className="grid gap-6 md:grid-cols-2">
                    <Reveal origin="bottom" distance={40} duration={800} className="h-full">
                        <div className="wm-card h-full rounded-3xl p-8">
                            <span className="mb-5 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-coral-50 text-coral-600">
                                <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </span>
                            <h3 className="mb-5 font-space text-2xl font-bold tracking-tight text-[#0F1A0C]">{problemTitle}</h3>
                            <ul className="space-y-3.5">
                                {problems.map(function (item, idx) {
                                    return (
                                        <li key={idx} className="flex items-start gap-3">
                                            <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-coral-50">
                                                <svg className="h-3 w-3 text-coral-500" fill="none" stroke="currentColor" strokeWidth={3} viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </span>
                                            <span className="text-sm leading-relaxed text-[#4A5B44]">{item}</span>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    </Reveal>
                    <Reveal origin="bottom" distance={40} duration={800} delay={120} className="h-full">
                        <div className="relative h-full overflow-hidden rounded-3xl border border-brand-500/30 bg-[#162610] p-8 shadow-2xl shadow-brand-950/25">
                            <div className="wm-grid-dark pointer-events-none absolute inset-0" />
                            <div className="relative">
                                <span className="mb-5 inline-flex h-11 w-11 items-center justify-center rounded-xl bg-brand-400/15 text-brand-300">
                                    <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                    </svg>
                                </span>
                                <h3 className="mb-2 font-space text-2xl font-bold tracking-tight text-white">{solutionTitle}</h3>
                                {solutionDesc ? <p className="mb-5 text-sm leading-relaxed text-white/65">{solutionDesc}</p> : null}
                                <ul className="space-y-3.5">
                                    {solutions.map(function (item, idx) {
                                        return (
                                            <li key={idx} className="flex items-start gap-3">
                                                <span className="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-brand-400/20">
                                                    <svg className="h-3 w-3 text-brand-300" fill="none" stroke="currentColor" strokeWidth={3} viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                    </svg>
                                                </span>
                                                <span className="text-sm leading-relaxed text-white/80">{item}</span>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </div>
                        </div>
                    </Reveal>
                </div>
            </div>
        </SectionShell>
    );
}

// ─── How It Works Section ─────────────────────────────────────────────────────

function HowItWorksSection({ landing, canRegister }) {
    const { t } = useTranslation();
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    const [lineDrawn, setLineDrawn] = useState(false);
    if (s('howitworks_enabled') !== '1') return null;
    const steps = [1, 2, 3]
        .map(function (i) { return { title: s('step_' + i + '_title'), desc: s('step_' + i + '_desc') }; })
        .filter(function (st) { return st.title; });
    return (
        <SectionShell variant="tint" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading badge={s('howitworks_badge')} title={s('howitworks_title')} subtitle={s('howitworks_subtitle')} />
                <Reveal as="div" className="relative" origin="bottom" distance={40} onVisible={function () { setLineDrawn(true); }}>
                    <div
                        className={
                            'wm-draw-line absolute left-1/6 right-1/6 top-10 hidden h-0.5 bg-gradient-to-r from-brand-400/30 via-brand-500/60 to-brand-400/30 lg:block ' +
                            (lineDrawn ? 'is-drawn' : '')
                        }
                    />
                    <div className="grid gap-8 lg:grid-cols-3">
                        {steps.map(function (step, idx) {
                            return (
                                <div key={idx} className="relative text-center">
                                    <div className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-2xl border border-white/10 bg-[#162610] font-space text-xl font-bold text-white shadow-lg shadow-brand-950/30">
                                        {idx + 1}
                                    </div>
                                    <div className="wm-card rounded-2xl p-6">
                                        <h3 className="mb-2 font-space text-lg font-semibold text-[#0F1A0C]">{step.title}</h3>
                                        <p className="text-sm leading-relaxed text-[#4A5B44]">{step.desc}</p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </Reveal>
                {canRegister ? (
                    <div className="mt-12 text-center">
                        <Link
                            href={route('register')}
                            className="inline-flex items-center gap-2 rounded-xl bg-brand-600 px-7 py-3.5 text-base font-bold text-white shadow-lg shadow-brand-950/20 transition-all duration-200 hover:bg-brand-700"
                        >
                            {t('welcome.get_started_free')}
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                            </svg>
                        </Link>
                    </div>
                ) : null}
            </div>
        </SectionShell>
    );
}

// ─── Pricing Section ───────────────────────────────────────────────────────────

function PricingSection({ plans }) {
    const { t } = useTranslation();
    const [yearly, setYearly] = useState(false);
    const toggleRef = useRef(null);
    const [pill, setPill] = useState({ left: 0, top: 0, width: 0, height: 0 });

    useLayoutEffect(function () {
        const container = toggleRef.current;
        if (!container) return;
        const btns = container.querySelectorAll('button');
        const active = btns[yearly ? 1 : 0];
        if (active) {
            setPill({ left: active.offsetLeft, top: active.offsetTop, width: active.offsetWidth, height: active.offsetHeight });
        }
    }, [yearly, plans]);

    if (!plans || !plans.length) return null;
    const twoCol = plans.length < 3;

    return (
        <SectionShell id="pricing" variant="light" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div className="text-center">
                    <SectionHeading
                        badge={t('welcome.badge_pricing')}
                        title={t('pricing.simple_transparent')}
                        subtitle={t('pricing.choose_plan_anytime')}
                    />
                </div>
                <div ref={toggleRef} className="relative mb-12 flex justify-center">
                    <div className="inline-flex items-center gap-1 rounded-xl border border-[#E2EBD9] bg-white p-1 shadow-soft-sm">
                        <span
                            aria-hidden="true"
                            className="absolute rounded-lg bg-[#162610] shadow-md transition-all duration-300 ease-out"
                            style={{ left: pill.left, top: pill.top, width: pill.width, height: pill.height }}
                        />
                        <button
                            type="button"
                            onClick={function () { setYearly(false); }}
                            className={'relative z-10 rounded-lg px-5 py-2 text-sm font-semibold transition-colors ' + (!yearly ? 'text-white' : 'text-[#4A5B44] hover:text-[#0F1A0C]')}
                        >
                            {t('welcome.monthly')}
                        </button>
                        <button
                            type="button"
                            onClick={function () { setYearly(true); }}
                            className={'relative z-10 flex items-center gap-2 rounded-lg px-5 py-2 text-sm font-semibold transition-colors ' + (yearly ? 'text-white' : 'text-[#4A5B44] hover:text-[#0F1A0C]')}
                        >
                            {t('welcome.yearly')}
                            <span className={'rounded-full px-1.5 py-0.5 text-xs font-bold ' + (yearly ? 'bg-white/20 text-white' : 'bg-brand-100 text-brand-700')}>-20%</span>
                        </button>
                    </div>
                </div>

                <div className={'grid gap-6 ' + (twoCol ? 'mx-auto max-w-2xl sm:grid-cols-2' : 'sm:grid-cols-2 lg:grid-cols-3')}>
                    {plans.map(function (plan, planIdx) {
                        const price = yearly ? plan.price_yearly : plan.price_monthly;
                        const featured = !!plan.is_featured;
                        const paid = Boolean(parseFloat(price));
                        const hasTrial = Boolean(plan.trial_days);
                        return (
                            <Reveal key={plan.id} interval={100} index={planIdx} origin="bottom" distance={40} duration={800} className="h-full">
                                <div
                                    className={
                                        'relative flex h-full flex-col rounded-3xl p-7 transition-all duration-300 ' +
                                        (featured
                                            ? 'border border-brand-400/50 bg-[#162610] text-white shadow-2xl shadow-brand-950/30 lg:-translate-y-2'
                                            : 'wm-card')
                                    }
                                >
                                    {featured ? (
                                        <div className="absolute -top-3.5 left-1/2 -translate-x-1/2">
                                            <span className="rounded-full bg-brand-500 px-3 py-1 text-xs font-bold text-white shadow-md">{t('welcome.most_popular')}</span>
                                        </div>
                                    ) : null}
                                    <div>
                                        <h3 className={'font-space text-lg font-bold ' + (featured ? 'text-white' : 'text-[#0F1A0C]')}>{plan.name}</h3>
                                        {plan.description ? (
                                            <p className={'mt-1 text-sm ' + (featured ? 'text-white/60' : 'text-[#4A5B44]')}>{plan.description}</p>
                                        ) : null}
                                    </div>
                                    <div className="mb-6 mt-5">
                                        <span className={'text-4xl font-bold tracking-tight ' + (featured ? 'text-white' : 'text-[#0F1A0C]')}>
                                            {price === 0 ? t('welcome.free') : '$' + parseFloat(price).toFixed(0)}
                                        </span>
                                        {paid ? (
                                            <span className={'ml-1 text-sm ' + (featured ? 'text-white/60' : 'text-[#4A5B44]')}>{yearly ? t('welcome.per_year') : t('welcome.per_month')}</span>
                                        ) : null}
                                        {hasTrial ? (
                                            <p className={'mt-1 text-xs ' + (featured ? 'text-white/50' : 'text-[#7A8C6E]')}>{t('pricing.trial_days', { days: plan.trial_days })}</p>
                                        ) : null}
                                    </div>
                                    {plan.features && plan.features.length ? (
                                        <ul className="mb-7 flex-1 space-y-2.5">
                                            {plan.features.map(function (feat, fi) {
                                                return (
                                                    <li key={fi} className="flex items-start gap-2.5 text-sm">
                                                        <svg className={'mt-0.5 h-4 w-4 flex-shrink-0 ' + (featured ? 'text-brand-300' : 'text-brand-500')} fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                        </svg>
                                                        <span className={featured ? 'text-white/80' : 'text-[#4A5B44]'}>{feat}</span>
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    ) : null}
                                    <Link
                                        href={route('register')}
                                        className={
                                            'block rounded-xl py-3 text-center text-sm font-bold transition-all duration-200 ' +
                                            (featured
                                                ? 'bg-brand-500 text-white hover:bg-brand-400'
                                                : 'border border-[#E2EBD9] bg-white text-[#0F1A0C] hover:border-brand-500/50 hover:text-brand-700')
                                        }
                                    >
                                        {featured ? t('welcome.get_started_plan') : t('welcome.upgrade')}
                                    </Link>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </SectionShell>
    );
}

// ─── Why Section ──────────────────────────────────────────────────────────────

function WhySection({ landing }) {
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    if (s('why_enabled') !== '1') return null;
    const items = [1, 2, 3, 4, 5, 6]
        .map(function (i) {
            return { icon: s('why_' + i + '_icon', 'zap'), title: s('why_' + i + '_title'), desc: s('why_' + i + '_desc') };
        })
        .filter(function (f) { return f.title; });
    if (!items.length) return null;
    return (
        <SectionShell variant="light" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading badge={s('why_badge')} title={s('why_title')} subtitle={s('why_subtitle')} />
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {items.map(function (item, idx) {
                        const wide = idx === 0;
                        return (
                            <Reveal key={idx} interval={100} index={idx} origin="bottom" distance={40} duration={800} className={wide ? 'sm:col-span-2 h-full' : 'h-full'}>
                                <div className={'wm-card flex h-full gap-4 rounded-2xl p-6 ' + (wide ? 'flex-col items-start bg-gradient-to-br from-white to-[#EDF4E4] sm:flex-row sm:items-center sm:p-8' : 'flex-col')}>
                                    <div className="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-brand-400 to-brand-600 text-white shadow-md shadow-brand-950/20">
                                        <FeatureIcon name={item.icon} className="h-6 w-6" />
                                    </div>
                                    <div>
                                        <h3 className={'mb-1 font-semibold text-[#0F1A0C] ' + (wide ? 'font-space text-xl' : 'text-base')}>{item.title}</h3>
                                        <p className={'leading-relaxed text-[#4A5B44] ' + (wide ? 'text-base' : 'text-sm')}>{item.desc}</p>
                                    </div>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </SectionShell>
    );
}

// ─── Testimonials Section ─────────────────────────────────────────────────────

function StarRating() {
    return (
        <div className="flex gap-0.5">
            {[0, 1, 2, 3, 4].map(function (i) {
                return (
                    <svg key={i} className="h-4 w-4 text-accent-400" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                    </svg>
                );
            })}
        </div>
    );
}

function TestimonialsSection({ landing }) {
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    if (s('testimonials_enabled') !== '1') return null;
    const testimonials = [1, 2, 3, 4, 5, 6]
        .map(function (i) {
            return {
                name: s('testimonial_' + i + '_name'),
                role: s('testimonial_' + i + '_role'),
                text: s('testimonial_' + i + '_text'),
                avatar: s('testimonial_' + i + '_avatar'),
            };
        })
        .filter(function (t) { return t.name && t.text; });
    if (!testimonials.length) return null;
    return (
        <TestimonialsCarousel
            testimonials={testimonials}
            title={s('testimonials_title')}
            badge={s('testimonials_badge')}
            subtitle={s('testimonials_subtitle')}
        />
    );
}

function TestimonialsCarousel(props) {
    const testimonials = props.testimonials;
    const containerRef = useRef(null);

    useEffect(function () {
        const el = containerRef.current;
        if (!el) return undefined;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return undefined;
        let paused = false;
        const pause = function () { paused = true; };
        const resume = function () { paused = false; };
        el.addEventListener('pointerenter', pause);
        el.addEventListener('pointerleave', resume);
        el.addEventListener('focusin', pause);
        el.addEventListener('focusout', resume);
        el.addEventListener('touchstart', pause, { passive: true });
        const id = setInterval(function () {
            if (paused) return;
            const max = el.scrollWidth - el.clientWidth - 4;
            const atEnd = !(el.scrollLeft < max);
            if (atEnd) {
                el.scrollTo({ left: 0, behavior: 'smooth' });
            } else {
                const card = el.querySelector('[data-tm-card]');
                const step = card ? card.getBoundingClientRect().width + 20 : el.clientWidth * 0.8;
                el.scrollBy({ left: step, behavior: 'smooth' });
            }
        }, 5000);
        return function () {
            clearInterval(id);
            el.removeEventListener('pointerenter', pause);
            el.removeEventListener('pointerleave', resume);
            el.removeEventListener('focusin', pause);
            el.removeEventListener('focusout', resume);
            el.removeEventListener('touchstart', pause);
        };
    }, []);

    const scrollByCard = function (dir) {
        const el = containerRef.current;
        if (!el) return;
        const card = el.querySelector('[data-tm-card]');
        const step = card ? card.getBoundingClientRect().width + 20 : el.clientWidth * 0.8;
        el.scrollBy({ left: dir * step, behavior: 'smooth' });
    };

    return (
        <SectionShell variant="dark" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading tone="dark" badge={props.badge} title={props.title} subtitle={props.subtitle} />
                <div className="group relative">
                    <div
                        ref={containerRef}
                        data-tm-track
                        className="wm-no-scrollbar -mx-4 flex snap-x snap-mandatory gap-5 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0"
                    >
                        {testimonials.map(function (t, idx) {
                            return (
                                <div key={idx} data-tm-card className="wm-glass flex w-[85vw] shrink-0 snap-start flex-col gap-4 rounded-2xl p-6 sm:w-[calc(50%-10px)] lg:w-[calc(33.333%-14px)]">
                                    <div className="flex items-center justify-between">
                                        <StarRating />
                                        <svg className="h-7 w-7 text-brand-400/40" fill="currentColor" viewBox="0 0 24 24">
                                            <path d="M14.017 21v-7.391c0-5.704 3.731-9.57 8.983-10.609l.995 2.151c-2.432.917-3.995 3.531-3.995 5.849h4v10h-9.983zm-14.017 0v-7.391c0-5.704 3.748-9.57 9-10.609l.996 2.151c-2.433.917-3.996 3.531-3.996 5.849h3.983v10h-9.983z" />
                                        </svg>
                                    </div>
                                    <blockquote className="flex-1 text-sm leading-relaxed text-white/80">{t.text}</blockquote>
                                    <div className="flex items-center gap-3 border-t border-white/10 pt-4">
                                        {t.avatar ? (
                                            <img src={t.avatar} alt={t.name} className="h-10 w-10 rounded-full object-cover ring-2 ring-brand-400/40" />
                                        ) : (
                                            <div className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-brand-400 to-brand-600 text-sm font-bold text-white ring-2 ring-brand-400/30">
                                                {t.name.charAt(0)}
                                            </div>
                                        )}
                                        <div>
                                            <p className="text-sm font-semibold text-white">{t.name}</p>
                                            <p className="text-xs text-white/55">{t.role}</p>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <button
                        type="button"
                        aria-label="Previous testimonial"
                        onClick={function () { scrollByCard(-1); }}
                        className="absolute -left-4 top-1/2 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-white/10 text-white opacity-0 backdrop-blur transition group-hover:opacity-100 focus:opacity-100 sm:flex"
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" />
                        </svg>
                    </button>
                    <button
                        type="button"
                        aria-label="Next testimonial"
                        onClick={function () { scrollByCard(1); }}
                        className="absolute -right-4 top-1/2 hidden h-10 w-10 -translate-y-1/2 items-center justify-center rounded-full border border-white/15 bg-white/10 text-white opacity-0 backdrop-blur transition group-hover:opacity-100 focus:opacity-100 sm:flex"
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" />
                        </svg>
                    </button>
                </div>
            </div>
        </SectionShell>
    );
}

// ─── FAQ Section ──────────────────────────────────────────────────────────────

function FaqSection({ landing }) {
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    if (s('faq_enabled') !== '1') return null;
    const faqs = [1, 2, 3, 4, 5]
        .map(function (i) { return { q: s('faq_' + i + '_q'), a: s('faq_' + i + '_a') }; })
        .filter(function (f) { return f.q && f.a; });
    if (!faqs.length) return null;
    return (
        <SectionShell id="faq" variant="tint" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <div className="grid gap-10 lg:grid-cols-5 lg:gap-16">
                    <div className="lg:col-span-2">
                        <div className="lg:sticky lg:top-28">
                            <SectionHeading
                                align="left"
                                badge={s('faq_badge')}
                                title={s('faq_title')}
                                subtitle={s('faq_subtitle')}
                            />
                            <div className="wm-card rounded-2xl p-6">
                                <h3 className="mb-1 font-space text-base font-semibold text-[#0F1A0C]">Still have questions?</h3>
                                <p className="mb-4 text-sm leading-relaxed text-[#4A5B44]">Our team replies in minutes during business hours.</p>
                                <Link href={route('contact')} className="inline-flex items-center gap-1.5 text-sm font-bold text-brand-600 hover:text-brand-700 transition-colors">
                                    Contact support
                                    <span aria-hidden="true">→</span>
                                </Link>
                            </div>
                        </div>
                    </div>
                    <div className="lg:col-span-3">
                        <FaqAccordion faqs={faqs} />
                    </div>
                </div>
            </div>
        </SectionShell>
    );
}

// ─── CTA Section ──────────────────────────────────────────────────────────────

function CtaSection({ landing, canRegister }) {
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    if (s('cta_enabled') !== '1') return null;
    return (
        <SectionShell variant="dark" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <Reveal origin="bottom" distance={40} duration={900}>
                    <div className="relative overflow-hidden rounded-[2rem] border border-brand-400/25 bg-gradient-to-b from-brand-400/10 to-transparent px-6 py-14 text-center sm:py-16">
                        <div className="pointer-events-none absolute inset-0" style={{ backgroundImage: 'radial-gradient(rgba(255,255,255,0.06) 1px, transparent 1px)', backgroundSize: '28px 28px' }} />
                        <h2 className="relative mx-auto max-w-2xl font-space text-3xl font-bold tracking-[-0.02em] text-white sm:text-4xl lg:text-[46px]">
                            {s('cta_title')}
                        </h2>
                        <p className="relative mx-auto mt-4 max-w-xl text-base text-white/65 sm:text-lg">{s('cta_subtitle')}</p>
                        <div className="relative mt-9 flex flex-wrap items-center justify-center gap-4">
                            {canRegister && s('cta_primary') ? (
                                <Link
                                    href={route('register')}
                                    className="wm-shimmer inline-flex items-center gap-2 rounded-xl bg-brand-500 px-7 py-3.5 text-base font-bold text-white shadow-lg shadow-brand-950/40 transition-all duration-200 hover:bg-brand-400"
                                >
                                    {s('cta_primary')}
                                    <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                    </svg>
                                </Link>
                            ) : null}
                            {s('cta_secondary') ? (
                                <Link
                                    href={route('contact')}
                                    className="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/5 px-7 py-3.5 text-base font-semibold text-white backdrop-blur transition-all duration-200 hover:bg-white/10"
                                >
                                    {s('cta_secondary')}
                                </Link>
                            ) : null}
                        </div>
                    </div>
                </Reveal>
            </div>
        </SectionShell>
    );
}

// ─── Metrics Section (numbers band) ───────────────────────────────────────────

function MetricsSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    const [inView, setInView] = useState(false);
    if (s('metrics_enabled') !== '1') return null;

    const metrics = [1, 2, 3, 4].map((i) => ({
        value: s(`metric_${i}_value`),
        label: s(`metric_${i}_label`),
    })).filter((m) => m.value);

    if (!metrics.length) return null;

    return (
        <section className="relative -mt-px border-b border-white/10" style={{ background: '#162610' }}>
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
                <div className="grid grid-cols-2 lg:grid-cols-4 gap-6">
                    {metrics.map((m, idx) => (
                        <Metric key={idx} metric={m} active={inView} index={idx} />
                    ))}
                </div>
            </div>
            {/* Observer sentinel: hidden, purely for triggering the count-up */}
            <Reveal
                aria-hidden="true"
                className="absolute inset-0 pointer-events-none"
                distance={0}
                duration={1}
                onVisible={() => setInView(true)}
            />
        </section>
    );
}

function Metric({ metric, active, index }) {
    const value = useCountUp(metric.value, active, 1400 + index * 150);
    return (
        <Reveal as="div" className="text-center" origin="bottom" distance={30} duration={700} index={index} interval={100}>
            <p className="text-3xl sm:text-4xl font-bold tracking-tight" style={{ color: '#5a8b38' }}>{value}</p>
            <p className="mt-1 text-sm text-white/55">{metric.label}</p>
        </Reveal>
    );
}

// ─── Channels Section ────────────────────────────────────────────────────────

function ChannelsSection({ landing }) {
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    if (s('channels_enabled') !== '1') return null;
    const channels = [1, 2, 3, 4, 5]
        .map(function (i) {
            return { key: s('channel_' + i + '_key', 'email'), title: s('channel_' + i + '_title'), desc: s('channel_' + i + '_desc') };
        })
        .filter(function (c) { return c.title; });
    if (!channels.length) return null;
    const tones = {
        whatsapp: 'text-[#25D366]',
        messenger: 'text-[#0084FF]',
        instagram: 'text-[#E1306C]',
        sms: 'text-[#9BC476]',
        email: 'text-[#FFBF00]',
    };
    return (
        <SectionShell variant="dark" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading tone="dark" badge={s('channels_badge')} title={s('channels_title')} subtitle={s('channels_subtitle')} />
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {channels.map(function (c, idx) {
                        return (
                            <Reveal key={idx} interval={100} index={idx} origin="bottom" distance={40} duration={800} className="h-full">
                                <div className="wm-glass h-full rounded-2xl p-6 transition-all duration-300 hover:border-brand-400/40">
                                    <div className={'mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-white/5 ' + (tones[c.key] || tones.email)}>
                                        <ChannelIcon name={c.key} className="h-6 w-6" />
                                    </div>
                                    <h3 className="mb-2 font-space text-lg font-semibold text-white">{c.title}</h3>
                                    <p className="text-sm leading-relaxed text-white/65">{c.desc}</p>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </SectionShell>
    );
}

// ─── Integration Brand Icon Components ─────────────────────────────────────

function WhatsAppIcon() {
    return (
        <svg className="w-full h-full text-[#25D366]" fill="currentColor" viewBox="0 0 24 24">
            <path d="M.057 24l1.687-6.163c-1.041-1.804-1.588-3.849-1.587-5.946.003-6.556 5.338-11.891 11.893-11.891 3.181.001 6.167 1.24 8.413 3.488 2.245 2.248 3.481 5.236 3.48 8.414-.003 6.557-5.338 11.892-11.893 11.892-1.99-.001-3.951-.5-5.688-1.448l-6.305 1.654zm6.597-3.807c1.676.995 3.276 1.591 5.392 1.592 5.448 0 9.886-4.434 9.889-9.885.002-5.462-4.415-9.89-9.881-9.892-5.452 0-9.887 4.434-9.889 9.884-.001 2.225.651 3.891 1.746 5.634l-.999 3.648 3.742-.981zm11.387-5.464c-.074-.124-.272-.198-.57-.347-.297-.149-1.758-.868-2.031-.967-.272-.099-.47-.149-.669.149-.198.297-.768.967-.941 1.165-.173.198-.347.223-.644.074-.297-.149-1.255-.462-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.297-.347.446-.521.151-.172.2-.296.3-.495.099-.198.05-.372-.025-.521-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.501-.669-.51l-.57-.01c-.198 0-.52.074-.792.372s-1.04 1.016-1.04 2.479 1.065 2.876 1.213 3.074c.149.198 2.095 3.2 5.076 4.487.709.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.695.248-1.29.173-1.414z" />
        </svg>
    );
}

function InstagramIcon() {
    return (
        <svg className="w-full h-full text-[#E1306C]" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" />
        </svg>
    );
}

function TwilioIcon() {
    return (
        <svg className="w-full h-full text-[#F22F46]" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 0C5.373 0 0 5.373 0 12s5.373 12 12 12 12-5.373 12-12S18.627 0 12 0zm-3 7.5a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm0 6a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm6-6a1.5 1.5 0 110 3 1.5 1.5 0 010-3zm0 6a1.5 1.5 0 110 3 1.5 1.5 0 010-3z" />
        </svg>
    );
}

function EmailIcon() {
    return (
        <svg className="w-full h-full text-[#EA4335]" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
        </svg>
    );
}

function OpenAIIcon() {
    return (
        <svg className="w-full h-full" shapeRendering="geometricPrecision" textRendering="geometricPrecision" imageRendering="optimizeQuality" fillRule="evenodd" clipRule="evenodd" viewBox="0 0 512 509.639">
            <path fill="#10A37F" d="M115.612 0h280.775C459.974 0 512 52.026 512 115.612v278.415c0 63.587-52.026 115.613-115.613 115.613H115.612C52.026 509.64 0 457.614 0 394.027V115.612C0 52.026 52.026 0 115.612 0z"/>
            <path fill="#ffffff" fillRule="nonzero" d="M412.037 221.764a90.834 90.834 0 004.648-28.67 90.79 90.79 0 00-12.443-45.87c-16.37-28.496-46.738-46.089-79.605-46.089-6.466 0-12.943.683-19.264 2.04a90.765 90.765 0 00-67.881-30.515h-.576c-.059.002-.149.002-.216.002-39.807 0-75.108 25.686-87.346 63.554-25.626 5.239-47.748 21.31-60.682 44.03a91.873 91.873 0 00-12.407 46.077 91.833 91.833 0 0023.694 61.553 90.802 90.802 0 00-4.649 28.67 90.804 90.804 0 0012.442 45.87c16.369 28.504 46.74 46.087 79.61 46.087a91.81 91.81 0 0019.253-2.04 90.783 90.783 0 0067.887 30.516h.576l.234-.001c39.829 0 75.119-25.686 87.357-63.588 25.626-5.242 47.748-21.312 60.682-44.033a91.718 91.718 0 0012.383-46.035 91.83 91.83 0 00-23.693-61.553l-.004-.005zM275.102 413.161h-.094a68.146 68.146 0 01-43.611-15.8 56.936 56.936 0 002.155-1.221l72.54-41.901a11.799 11.799 0 005.962-10.251V241.651l30.661 17.704c.326.163.55.479.596.84v84.693c-.042 37.653-30.554 68.198-68.21 68.273h.001zm-146.689-62.649a68.128 68.128 0 01-9.152-34.085c0-3.904.341-7.817 1.005-11.663.539.323 1.48.897 2.155 1.285l72.54 41.901a11.832 11.832 0 0011.918-.002l88.563-51.137v35.408a1.1 1.1 0 01-.438.94l-73.33 42.339a68.43 68.43 0 01-34.11 9.12 68.359 68.359 0 01-59.15-34.11l-.001.004zm-19.083-158.36a68.044 68.044 0 0135.538-29.934c0 .625-.036 1.731-.036 2.5v83.801l-.001.07a11.79 11.79 0 005.954 10.242l88.564 51.13-30.661 17.704a1.096 1.096 0 01-1.034.093l-73.337-42.375a68.36 68.36 0 01-34.095-59.143 68.412 68.412 0 019.112-34.085l-.004-.003zm251.907 58.621l-88.563-51.137 30.661-17.697a1.097 1.097 0 011.034-.094l73.337 42.339c21.109 12.195 34.132 34.746 34.132 59.132 0 28.604-17.849 54.199-44.686 64.078v-86.308c.004-.032.004-.065.004-.096 0-4.219-2.261-8.119-5.919-10.217zm30.518-45.93c-.539-.331-1.48-.898-2.155-1.286l-72.54-41.901a11.842 11.842 0 00-5.958-1.611c-2.092 0-4.15.558-5.957 1.611l-88.564 51.137v-35.408l-.001-.061a1.1 1.1 0 01.44-.88l73.33-42.303a68.301 68.301 0 0134.108-9.129c37.704 0 68.281 30.577 68.281 68.281a68.69 68.69 0 01-.984 11.545v.005zm-191.843 63.109l-30.668-17.704a1.09 1.09 0 01-.596-.84v-84.692c.016-37.685 30.593-68.236 68.281-68.236a68.332 68.332 0 0143.689 15.804 63.09 63.09 0 00-2.155 1.222l-72.54 41.9a11.794 11.794 0 00-5.961 10.248v.068l-.05 102.23zm16.655-35.91l39.445-22.782 39.444 22.767v45.55l-39.444 22.767-39.445-22.767v-45.535z"/>
        </svg>
    );
}

function ChatGPTIcon() {
    return <OpenAIIcon />;
}

function GeminiIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 0 24 24" fill="none">
            <rect width="24" height="24" rx="6" fill="#1A73E8" />
            <path d="M12 4C12 8.418 8.418 12 4 12C8.418 12 12 15.582 12 20C12 15.582 15.582 12 20 12C15.582 12 12 8.418 12 4Z" fill="#FFFFFF" />
        </svg>
    );
}

function ClaudeIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 0 24 24" fill="none">
            <rect width="24" height="24" rx="6" fill="#D97757" />
            <path d="M14.7 5.5h-5.4L3.5 19.5h4.6l1.7-4.3h6.4l1.7 4.3h4.6L14.7 5.5zm-4.6 8.3l2-5.1 2 5.1h-4z" fill="#FFFFFF" />
        </svg>
    );
}

function GroqIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 0 24 24" fill="none">
            <rect width="24" height="24" rx="6" fill="#F05A28" />
            <path d="M12 5a7 7 0 107 7h-3a4 4 0 11-4-4v-3z" fill="#FFFFFF" />
        </svg>
    );
}

function GrokIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 0 24 24" fill="none">
            <rect width="24" height="24" rx="6" fill="#000000" />
            <path d="M7 17L17 7M17 17L7 7" stroke="#FFFFFF" strokeWidth="2.5" strokeLinecap="round" />
            <circle cx="12" cy="12" r="2.2" fill="#FFFFFF" />
        </svg>
    );
}

function ShopifyIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg">
            <circle cx="512" cy="512" r="512" style={{ fill: '#95bf47' }} />
            <path d="M597.3 308s-6 1.7-15.9 4.7c-1.7-5.6-4.3-12-7.7-18.9-11.2-21.5-27.9-33.1-47.7-33.1-1.3 0-2.6 0-4.3.4-.4-.9-1.3-1.3-1.7-2.1-8.6-9.5-19.8-13.8-33.1-13.3-25.8.9-51.6 19.3-72.2 52.4-14.6 23.2-25.8 52.4-28.8 75.2-29.7 9-50.3 15.5-50.7 15.9-15 4.7-15.5 5.2-17.2 19.3-1.3 10.7-40.8 313.4-40.8 313.4l325 56.3V307.6c-2.7 0-4 .4-4.9.4zm-75.2 23.2c-17.2 5.2-36.1 11.2-54.6 16.8 5.2-20.2 15.5-40.4 27.5-53.7 4.7-4.7 11.2-10.3 18.5-13.8 7.3 15.5 9 36.6 8.6 50.7zm-35.2-67.9c6 0 11.2 1.3 15.5 3.9-6.9 3.4-13.8 9-20.2 15.5-16.3 17.6-28.8 45.1-34 71.4-15.5 4.7-31 9.5-45.1 13.8 9.4-41.4 44.2-103.7 83.8-104.6zM437 499.8c1.7 27.5 74.4 33.5 78.7 98.4 3 51.2-27.1 86-70.5 88.6-52.4 3.4-81.2-27.5-81.2-27.5l11.2-47.3s28.8 21.9 52 20.2c15-.9 20.6-13.3 20.2-21.9-2.1-36.1-61.5-34-65.3-93.3-3.4-49.9 29.2-100.2 101.5-104.9 27.9-1.7 42.1 5.2 42.1 5.2l-16.3 61.9s-18.5-8.6-40.4-6.9c-32 2.1-32.4 22.3-32 27.5zm102.3-173.7c0-12.9-1.7-31.4-7.7-46.9 19.8 3.9 29.2 25.8 33.5 39.1-7.7 2.2-16.3 4.8-25.8 7.8zM612 777.5 747 744s-58-392.5-58.5-395.1c-.4-2.6-2.6-4.3-4.7-4.3s-40-.9-40-.9-23.2-22.4-31.8-31v464.8z" style={{ fill: '#fff' }} />
        </svg>
    );
}

function WooCommerceIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 -51.5 256 256" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid">
            <g fillRule="evenodd">
                <path d="M232.137523,0 L23.7592719,0 C10.5720702,0 -0.103257595,10.7799647 0.000639559736,23.862498 L0.000639559736,103.404247 C0.000639559736,116.591115 10.6767385,127.266612 23.8639402,127.266612 L122.558181,127.266612 L167.667206,152.384995 L157.410382,127.266612 L232.137523,127.266612 C245.325059,127.266612 255.999888,116.591115 255.999888,103.404247 L255.999888,23.862498 C255.999888,10.6752964 245.325059,0 232.137523,0 Z M19.3640061,18.4201481 C16.4334946,18.6294847 14.2355942,19.6761007 12.7703719,21.6645976 C11.3051496,23.5484931 10.781875,25.9556632 11.095813,28.6768382 C17.2707741,67.9247058 23.027062,94.4034429 28.3647436,108.113986 C30.4579757,113.137395 32.8651458,115.5451 35.690989,115.335764 C40.086656,115.021425 45.3196694,108.951332 51.4946305,97.1248185 C54.73908,90.4260478 59.7628237,80.3792294 66.5657276,66.9823567 C72.2170797,86.762992 79.9618646,101.625221 89.6956814,111.567705 C92.417057,114.393415 95.2427665,115.649434 97.9634733,115.440098 C100.371178,115.230761 102.254539,113.974742 103.510558,111.672039 C104.557241,109.683676 104.975914,107.380974 104.766578,104.764601 C104.138568,95.2407893 105.080917,81.9489193 107.69729,64.8892584 C110.417997,47.3063898 113.767382,34.6424627 117.849111,27.1069476 C118.686458,25.5370569 119.000128,23.9670994 118.895794,22.0832708 C118.686458,19.6760338 117.639775,17.6875369 115.651411,16.1176463 C113.663048,14.5477557 111.46468,13.8151445 109.057643,14.0244142 C106.022597,14.2337508 103.719895,15.6989731 102.150205,18.6294847 C95.6614396,30.4560654 91.0560348,49.6088916 88.335328,76.1924977 C84.3579328,66.145211 81.0085475,54.3185634 78.3921746,40.3987506 C77.2411578,34.2238564 74.4154483,31.2933449 69.8100434,31.6073497 C66.670329,31.8166194 64.0538892,33.9098515 61.9606572,37.8869122 L39.0401069,81.5302462 C35.2723159,66.3544807 31.7138615,47.8296643 28.4694119,25.9556632 C27.7368007,20.5133802 24.7016209,18.0014749 19.3640061,18.4201481 Z M221.044022,25.9559976 C228.475136,27.5259551 234.022221,31.5030159 237.789611,38.0965832 C241.138996,43.7482697 242.81302,50.5511737 242.81302,58.7146317 C242.81302,69.4943957 240.092314,79.3325464 234.649562,88.3333508 C228.370134,98.7995112 220.206676,104.032257 210.054854,104.032257 C208.275828,104.032257 206.391799,103.82292 204.402767,103.404247 C196.972321,101.834557 191.425236,97.857831 187.657177,91.264063 C184.308461,85.5076413 182.633768,78.6002028 182.633768,70.5410787 C182.633768,59.7612478 185.355144,49.923164 190.797226,41.0270948 C197.181658,30.5610681 205.345116,25.3280548 215.392603,25.3280548 C217.171629,25.3280548 219.055659,25.5373913 221.044022,25.9559976 Z M216.648622,82.5769291 C220.521015,79.1232099 223.137388,73.9947979 224.602744,67.0873594 C225.021417,64.6802562 225.335087,62.0637496 225.335087,59.3425746 C225.335087,56.3074616 224.707078,53.0630121 223.451058,49.8184957 C221.881368,45.7367667 219.788002,43.5389332 217.275963,43.0155917 C213.508574,42.2829805 209.845518,44.3762126 206.391799,49.5045577 C203.566089,53.4816184 201.786394,57.6680157 200.844713,61.9590813 C200.321038,64.3663182 200.111701,66.9830255 200.111701,69.5993984 C200.111701,72.6344445 200.739711,75.8788272 201.99573,79.1232099 C203.566089,83.2049389 205.658786,85.4026386 208.170825,85.9263145 C210.787198,86.4493215 213.612908,85.2983047 216.648622,82.5769291 Z M172.167608,38.0965832 C168.399549,31.5030159 162.74813,27.5259551 155.422019,25.9559976 C153.432987,25.5373913 151.549626,25.3280548 149.769931,25.3280548 C139.723112,25.3280548 131.559654,30.5610681 125.175223,41.0270948 C119.732472,49.923164 117.011765,59.7612478 117.011765,70.5410787 C117.011765,78.6002028 118.686458,85.5076413 122.035174,91.264063 C125.803233,97.857831 131.350318,101.834557 138.780763,103.404247 C140.769126,103.82292 142.653156,104.032257 144.432851,104.032257 C154.584672,104.032257 162.74813,98.7995112 169.027559,88.3333508 C174.47031,79.3325464 177.191017,69.4943957 177.191017,58.7146317 C177.191017,50.5511737 175.516324,43.7482697 172.167608,38.0965832 Z M158.980072,67.0873594 C157.515384,73.9947979 154.898343,79.1232099 151.02595,82.5769291 C147.990904,85.2983047 145.165195,86.4493215 142.548822,85.9263145 C140.036783,85.4026386 137.943417,83.2049389 136.373727,79.1232099 C135.117707,75.8788272 134.489698,72.6344445 134.489698,69.5993984 C134.489698,66.9830255 134.699034,64.3663182 135.22271,61.9590813 C136.16439,57.6680157 137.943417,53.4816184 140.769126,49.5045577 C144.223514,44.3762126 147.88657,42.2829805 151.65396,43.0155917 C154.165999,43.5389332 156.259365,45.7367667 157.829055,49.8184957 C159.085074,53.0630121 159.713084,56.3074616 159.713084,59.3425746 C159.713084,62.0637496 159.503748,64.6802562 158.980072,67.0873594 Z" fill="#7F54B3" />
            </g>
        </svg>
    );
}

function StripeIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 0 24 24" fill="none">
            <rect width="24" height="24" rx="6" fill="#635BFF" />
            <path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.763-1.444 2.196-1.444 2.378 0 4.14 1.155 4.14 1.155l.807-3.418S15.82 2.05 12.825 2.05C8.42 2.05 5.56 4.316 5.56 7.777c0 4.57 6.299 4.793 6.299 7.25 0 .99-.865 1.583-2.455 1.583-2.61 0-4.87-1.433-4.87-1.433L3.6 18.706s2.348 1.488 5.765 1.488c4.618 0 7.7-2.22 7.7-5.836 0-4.875-6.44-5.063-6.44-7.234z" fill="#FFFFFF" />
        </svg>
    );
}

function PayPalIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="24" cy="24" r="20" fill="#0070BA"/>
            <path d="M32.3305 18.0977C32.3082 18.24 32.2828 18.3856 32.2542 18.5351C31.2704 23.5861 27.9046 25.331 23.606 25.331H21.4173C20.8916 25.331 20.4486 25.7127 20.3667 26.2313L19.2461 33.3381L18.9288 35.3527C18.8755 35.693 19.1379 36 19.4815 36H23.3634C23.8231 36 24.2136 35.666 24.286 35.2127L24.3241 35.0154L25.055 30.3772L25.1019 30.1227C25.1735 29.6678 25.5648 29.3338 26.0245 29.3338H26.6051C30.3661 29.3338 33.3103 27.8068 34.1708 23.388C34.5303 21.5421 34.3442 20.0008 33.393 18.9168C33.1051 18.59 32.748 18.3188 32.3305 18.0977Z" fill="white" fillOpacity="0.6"/>
            <path d="M31.3009 17.6871C31.1506 17.6434 30.9955 17.6036 30.8364 17.5678C30.6766 17.5328 30.5127 17.5018 30.3441 17.4748C29.754 17.3793 29.1074 17.334 28.4147 17.334H22.5676C22.4237 17.334 22.2869 17.3666 22.1644 17.4254C21.8948 17.5551 21.6944 17.8104 21.6459 18.1229L20.402 26.0013L20.3662 26.2311C20.4481 25.7126 20.8911 25.3308 21.4168 25.3308H23.6055C27.9041 25.3308 31.2699 23.5851 32.2537 18.5349C32.2831 18.3854 32.3078 18.2398 32.33 18.0975C32.0811 17.9655 31.8115 17.8525 31.5212 17.7563C31.4496 17.7324 31.3757 17.7094 31.3009 17.6871Z" fill="white" fillOpacity="0.8"/>
            <path d="M21.6461 18.1231C21.6946 17.8105 21.895 17.5552 22.1646 17.4264C22.2879 17.3675 22.4239 17.3349 22.5678 17.3349H28.4149C29.1077 17.3349 29.7542 17.3803 30.3444 17.4757C30.513 17.5027 30.6768 17.5338 30.8367 17.5687C30.9957 17.6045 31.1508 17.6443 31.3011 17.688C31.3759 17.7103 31.4498 17.7334 31.5222 17.7564C31.8125 17.8527 32.0821 17.9664 32.331 18.0976C32.6237 16.231 32.3287 14.9601 31.3194 13.8093C30.2068 12.5424 28.1986 12 25.629 12H18.169C17.6441 12 17.1963 12.3817 17.1152 12.9011L14.0079 32.5969C13.9467 32.9866 14.2473 33.3381 14.6402 33.3381H19.2458L20.4022 26.0014L21.6461 18.1231Z" fill="white"/>
        </svg>
    );
}

function RazorpayIcon() {
    return (
        <svg className="w-full h-full text-[#0C2340]" fill="currentColor" viewBox="0 0 24 24">
            <path d="M22.436 0l-11.91 7.773-1.174 4.276 6.625-4.323-2.647 9.626 7.423-17.352zm-15.549 10.158l-6.887 13.842h4.743l4.634-9.317-2.49-4.525z" />
        </svg>
    );
}

function PaystackIcon() {
    return (
        <svg className="w-full h-full text-[#09A5DB]" fill="currentColor" viewBox="0 0 24 24">
            <path d="M2 4h20v3.5H2V4zm0 6.5h20v3.5H2v-3.5zm0 6.5h13v3.5H2V17z" />
        </svg>
    );
}

function FacebookIcon() {
    return (
        <svg className="w-full h-full text-[#1877F2]" fill="currentColor" viewBox="0 0 24 24">
            <path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z" />
        </svg>
    );
}

function LinkedInIcon() {
    return (
        <svg className="w-full h-full text-[#0A66C2]" fill="currentColor" viewBox="0 0 24 24">
            <path d="M19 3a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h14m-.5 15.5v-5.3a3.26 3.26 0 0 0-3.26-3.26c-.85 0-1.84.52-2.28 1.3v-1.11h-2.79v8.37h2.79v-4.93c0-.77.62-1.4 1.39-1.4a1.4 1.4 0 0 1 1.4 1.4v4.93h2.75M6.46 10.9v8.37H9.25V10.9H6.46M7.86 6.77a1.6 1.6 0 1 0 0 3.2 1.6 1.6 0 0 0 0-3.2z" />
        </svg>
    );
}

function XIcon() {
    return (
        <svg className="w-full h-full text-black" fill="currentColor" viewBox="0 0 24 24">
            <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z" />
        </svg>
    );
}

function GoogleMapsIcon() {
    return (
        <svg className="w-full h-full" viewBox="0 0 24 24" fill="none">
            <path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5a2.5 2.5 0 110-5 2.5 2.5 0 010 5z" fill="#EA4335" />
        </svg>
    );
}

// ─── Integrations Circular Orbit Section ───────────────────────────────────────

function IntegrationsStripSection({ landing }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;

    return (
        <section id="integrations" className="relative bg-[#F2F7EC] py-20 sm:py-28 px-4 sm:px-6 lg:px-8 overflow-hidden text-[#0A0A0B]">
            {/* Ambient Background Glow */}
            <div className="absolute inset-0 bg-hero-grid-pattern opacity-60 pointer-events-none" />
            <div className="absolute inset-0 hero-radial-glow pointer-events-none" />

            <div className="relative max-w-7xl mx-auto text-center z-10">
                {/* ORBIT CONTAINER WITH CONCENTRIC RINGS */}
                <div className="relative w-full max-w-5xl mx-auto h-[620px] sm:h-[700px] md:h-[760px] flex items-center justify-center overflow-hidden sm:overflow-visible">
                    
                    {/* CONCENTRIC CIRCULAR ORBIT RINGS */}
                    <div className="absolute inset-0 flex items-center justify-center pointer-events-none">
                        {/* Ring 1: Inner Orbit Circle */}
                        <div className="w-[340px] h-[340px] sm:w-[440px] sm:h-[440px] md:w-[480px] md:h-[480px] rounded-full border border-[#467235]/20 bg-white/20 backdrop-blur-[2px] shadow-sm animate-pulse" style={{ animationDuration: '6s' }} />

                        {/* Ring 2: Middle Orbit Circle */}
                        <div className="w-[540px] h-[540px] sm:w-[680px] sm:h-[680px] md:w-[740px] md:h-[740px] rounded-full border border-[#FFBF00]/25 bg-transparent" />

                        {/* Ring 3: Outer Orbit Circle */}
                        <div className="w-[740px] h-[740px] sm:w-[920px] sm:h-[920px] md:w-[1000px] md:h-[1000px] rounded-full border border-[#467235]/15 bg-transparent" />
                    </div>

                    {/* CENTERED FOCAL HEADLINE & COPY BLOCK */}
                    <div className="relative z-20 max-w-md sm:max-w-lg mx-auto p-6 sm:p-8 wm-card rounded-3xl backdrop-blur-md text-center">
                        <Reveal as="div" origin="bottom" distance={15} duration={600}>
                            <div className="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-[#F2F7EC] border border-[#D5E5C9] mb-4 shadow-2xs">
                                <span className="w-2 h-2 rounded-full bg-[#467235] animate-pulse" />
                                <span className="font-space text-[10px] sm:text-xs font-bold tracking-[0.14em] uppercase text-[#345927]">
                                    SEAMLESS INTEGRATIONS
                                </span>
                            </div>
                        </Reveal>

                        <Reveal as="div" origin="bottom" distance={20} duration={700} delay={80}>
                            <h2 className="font-space text-2xl sm:text-4xl font-bold tracking-[-0.02em] text-[#0F1A0C] leading-tight mb-4">
                                Integrate your <br className="hidden sm:inline" />
                                <span className="relative inline-block text-[#467235]">
                                    favorite tools
                                    <span className="absolute bottom-1 left-0 w-full h-[3px] bg-[#FFBF00] rounded-full opacity-90 -z-10" />
                                </span>
                            </h2>
                        </Reveal>

                        <Reveal as="div" origin="bottom" distance={20} duration={700} delay={160}>
                            <p className="text-xs sm:text-sm text-[#4A5B44] leading-relaxed font-normal">
                                Connect WhatsMine with the platforms your business already uses and bring customer conversations, automation and growth workflows into one place.
                            </p>
                        </Reveal>
                    </div>

                    {/* 18 REAL INTEGRATION BRAND LOGO TILES AROUND ORBIT RINGS */}

                    {/* ── RING 1: INNER ORBIT (5 Apps) ────────────────────── */}
                    {/* 1. Shopify */}
                    <div className="absolute top-[22%] left-[34%] sm:top-[23%] sm:left-[35%] z-30 animate-float-1 group" title="Shopify">
                        <div className="w-11 h-11 sm:w-13 sm:h-13 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#95BF47] group-hover:shadow-lg">
                            <ShopifyIcon />
                        </div>
                    </div>

                    {/* 2. WhatsApp Business */}
                    <div className="absolute top-[23%] right-[33%] sm:top-[24%] sm:right-[34%] z-30 animate-float-2 group" title="WhatsApp Business">
                        <div className="w-11 h-11 sm:w-13 sm:h-13 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#25D366] group-hover:shadow-lg">
                            <WhatsAppIcon />
                        </div>
                    </div>

                    {/* 3. OpenAI */}
                    <div className="absolute bottom-[24%] right-[34%] sm:bottom-[25%] sm:right-[35%] z-30 animate-float-3 group" title="OpenAI">
                        <div className="w-11 h-11 sm:w-13 sm:h-13 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#10A37F] group-hover:shadow-lg">
                            <OpenAIIcon />
                        </div>
                    </div>

                    {/* 4. Stripe */}
                    <div className="absolute bottom-[23%] left-[34%] sm:bottom-[24%] sm:left-[35%] z-30 animate-float-4 group" title="Stripe">
                        <div className="w-11 h-11 sm:w-13 sm:h-13 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#635BFF] group-hover:shadow-lg">
                            <StripeIcon />
                        </div>
                    </div>

                    {/* 5. Google Gemini */}
                    <div className="absolute top-[48%] left-[28%] sm:left-[29%] z-30 animate-float-5 group" title="Google Gemini">
                        <div className="w-11 h-11 sm:w-13 sm:h-13 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#1A73E8] group-hover:shadow-lg">
                            <GeminiIcon />
                        </div>
                    </div>

                    {/* ── RING 2: MIDDLE ORBIT (6 Apps) ───────────────────── */}
                    {/* 6. Instagram */}
                    <div className="absolute top-[12%] left-[22%] sm:top-[13%] sm:left-[23%] z-30 animate-float-2 group" title="Instagram">
                        <div className="w-10 h-10 sm:w-12 sm:h-12 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#E1306C] group-hover:shadow-lg">
                            <InstagramIcon />
                        </div>
                    </div>

                    {/* 7. Anthropic Claude */}
                    <div className="absolute top-[13%] right-[22%] sm:top-[14%] sm:right-[23%] z-30 animate-float-1 group" title="Anthropic Claude">
                        <div className="w-10 h-10 sm:w-12 sm:h-12 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#D97757] group-hover:shadow-lg">
                            <ClaudeIcon />
                        </div>
                    </div>

                    {/* 8. WooCommerce */}
                    <div className="absolute bottom-[14%] right-[22%] sm:bottom-[15%] sm:right-[23%] z-30 animate-float-4 group" title="WooCommerce">
                        <div className="w-10 h-10 sm:w-12 sm:h-12 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#96588A] group-hover:shadow-lg">
                            <WooCommerceIcon />
                        </div>
                    </div>

                    {/* 9. SMS / Twilio */}
                    <div className="absolute bottom-[13%] left-[22%] sm:bottom-[14%] sm:left-[23%] z-30 animate-float-3 group" title="Twilio SMS">
                        <div className="w-10 h-10 sm:w-12 sm:h-12 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#F22F46] group-hover:shadow-lg">
                            <TwilioIcon />
                        </div>
                    </div>

                    {/* 10. PayPal */}
                    <div className="absolute top-[48%] right-[18%] sm:right-[19%] z-30 animate-float-5 group" title="PayPal">
                        <div className="w-10 h-10 sm:w-12 sm:h-12 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#003087] group-hover:shadow-lg">
                            <PayPalIcon />
                        </div>
                    </div>

                    {/* 11. Facebook */}
                    <div className="absolute top-[68%] left-[19%] sm:left-[20%] z-30 animate-float-1 group" title="Facebook">
                        <div className="w-10 h-10 sm:w-12 sm:h-12 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2.5 transition-all duration-300 group-hover:scale-110 group-hover:border-[#1877F2] group-hover:shadow-lg">
                            <FacebookIcon />
                        </div>
                    </div>

                    {/* ── RING 3: OUTER ORBIT (7 Apps) ───────────────────── */}
                    {/* 12. LinkedIn */}
                    <div className="absolute top-[4%] left-[12%] sm:top-[5%] sm:left-[13%] z-30 animate-float-3 group" title="LinkedIn">
                        <div className="w-10 h-10 sm:w-11 sm:h-11 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2 transition-all duration-300 group-hover:scale-110 group-hover:border-[#0A66C2] group-hover:shadow-lg">
                            <LinkedInIcon />
                        </div>
                    </div>

                    {/* 13. Google Maps */}
                    <div className="absolute top-[4%] right-[13%] sm:top-[5%] sm:right-[14%] z-30 animate-float-2 group" title="Google Maps">
                        <div className="w-10 h-10 sm:w-11 sm:h-11 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2 transition-all duration-300 group-hover:scale-110 group-hover:border-[#EA4335] group-hover:shadow-lg">
                            <GoogleMapsIcon />
                        </div>
                    </div>

                    {/* 14. Groq */}
                    <div className="absolute top-[48%] right-[7%] sm:right-[8%] z-30 animate-float-1 group" title="Groq AI">
                        <div className="w-10 h-10 sm:w-11 sm:h-11 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2 transition-all duration-300 group-hover:scale-110 group-hover:border-[#F05A28] group-hover:shadow-lg">
                            <GroqIcon />
                        </div>
                    </div>

                    {/* 15. Paystack */}
                    <div className="absolute bottom-[6%] right-[13%] sm:bottom-[7%] sm:right-[14%] z-30 animate-float-5 group" title="Paystack">
                        <div className="w-10 h-10 sm:w-11 sm:h-11 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2 transition-all duration-300 group-hover:scale-110 group-hover:border-[#09A5DB] group-hover:shadow-lg">
                            <PaystackIcon />
                        </div>
                    </div>

                    {/* 16. Razorpay */}
                    <div className="absolute bottom-[5%] left-[13%] sm:bottom-[6%] sm:left-[14%] z-30 animate-float-4 group" title="Razorpay">
                        <div className="w-10 h-10 sm:w-11 sm:h-11 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2 transition-all duration-300 group-hover:scale-110 group-hover:border-[#0C2340] group-hover:shadow-lg">
                            <RazorpayIcon />
                        </div>
                    </div>

                    {/* 17. X (Twitter) */}
                    <div className="absolute top-[48%] left-[7%] sm:left-[8%] z-30 animate-float-2 group" title="X (Twitter)">
                        <div className="w-10 h-10 sm:w-11 sm:h-11 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2 transition-all duration-300 group-hover:scale-110 group-hover:border-black group-hover:shadow-lg">
                            <XIcon />
                        </div>
                    </div>

                    {/* 18. Email */}
                    <div className="absolute top-[24%] left-[10%] sm:left-[11%] z-30 animate-float-3 group" title="Email">
                        <div className="w-10 h-10 sm:w-11 sm:h-11 bg-white rounded-2xl shadow-md shadow-black/5 border border-[#E2EBD9] flex items-center justify-center p-2 transition-all duration-300 group-hover:scale-110 group-hover:border-[#EA4335] group-hover:shadow-lg">
                            <EmailIcon />
                        </div>
                    </div>
                </div>

                {/* BOTTOM FOOTER SUBTEXT & CTA */}
                <div className="mt-12 sm:mt-16 text-center space-y-3 z-20 relative">
                    <p className="text-xs sm:text-sm font-medium text-[#5B6B52]">
                        Connect more with APIs, Webhooks and custom integrations.
                    </p>
                    <div>
                        <Link
                            href="/integrations"
                            className="inline-flex items-center gap-1.5 text-xs sm:text-sm font-bold text-[#467235] hover:text-[#283F24] transition-colors hover:underline group"
                        >
                            <span>View all integrations</span>
                            <span className="group-hover:translate-x-1 transition-transform">→</span>
                        </Link>
                    </div>
                </div>
            </div>
        </section>
    );
}

// ─── Security Section ─────────────────────────────────────────────────────────

function SecuritySection({ landing }) {
    const s = function (key, def = '') { return landing['landing.' + key] ?? def; };
    if (s('security_enabled') !== '1') return null;
    const items = [1, 2, 3, 4]
        .map(function (i) { return { icon: s('security_' + i + '_icon', 'shield-check'), title: s('security_' + i + '_title'), desc: s('security_' + i + '_desc') }; })
        .filter(function (it) { return it.title; });
    if (!items.length) return null;
    return (
        <SectionShell variant="dark" className="py-20 sm:py-28">
            <div className="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <SectionHeading tone="dark" badge={s('security_badge')} title={s('security_title')} subtitle={s('security_subtitle')} />
                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    {items.map(function (item, idx) {
                        return (
                            <Reveal key={idx} interval={100} index={idx} origin="bottom" distance={40} duration={800} className="h-full">
                                <div className="wm-glass h-full rounded-2xl p-6 transition-all duration-300 hover:border-brand-400/40">
                                    <span className="mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-brand-400/15 text-brand-300">
                                        <FeatureIcon name={item.icon} className="h-6 w-6" />
                                    </span>
                                    <h3 className="mb-1 font-space text-base font-semibold text-white">{item.title}</h3>
                                    <p className="text-sm leading-relaxed text-white/65">{item.desc}</p>
                                </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </SectionShell>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function Welcome({ auth, canLogin, canRegister, landing = {}, plans = [] }) {
    const appName = import.meta.env.VITE_APP_NAME || 'wa.careerinpak.com';
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;

    const metaTitle = s('seo_title') || s('hero_title') || appName;
    const metaDesc  = s('seo_description') || s('hero_subtitle') || '';
    const origin = typeof window !== 'undefined' ? window.location.origin : '';

    // ── JSON-LD structured data ────────────────────────────────────────────────
    const faqs = [1, 2, 3, 4, 5]
        .map((i) => ({ q: s(`faq_${i}_q`), a: s(`faq_${i}_a`) }))
        .filter((f) => f.q && f.a);

    const jsonLd = [
        {
            '@context': 'https://schema.org',
            '@type': 'Organization',
            name: appName,
            url: origin || undefined,
            logo: origin ? `${origin}/favicon.ico` : undefined,
            description: metaDesc,
        },
        {
            '@context': 'https://schema.org',
            '@type': 'SoftwareApplication',
            name: appName,
            applicationCategory: 'BusinessApplication',
            operatingSystem: 'Web',
            description: metaDesc,
            offers: { '@type': 'Offer', price: '0', priceCurrency: 'USD' },
            aggregateRating: { '@type': 'AggregateRating', ratingValue: '4.9', ratingCount: '1280' },
        },
    ];

    if (faqs.length) {
        jsonLd.push({
            '@context': 'https://schema.org',
            '@type': 'FAQPage',
            mainEntity: faqs.map((f) => ({
                '@type': 'Question',
                name: f.q,
                acceptedAnswer: { '@type': 'Answer', text: f.a },
            })),
        });
    }

    return (
        <LandingLayout>
            <SeoHead
                title={metaTitle}
                description={metaDesc}
                keywords={s('seo_keywords')}
                image={s('seo_og_image') || undefined}
                jsonLd={jsonLd}
            />

            <Preloader />
            <HeroSection landing={landing} canLogin={canLogin} canRegister={canRegister} auth={auth} />
            <UnifiedInboxSection landing={landing} />
            <MetricsSection landing={landing} />
            <TrustedBySection landing={landing} />
            <ChannelsSection landing={landing} />
            <ProblemSolutionSection landing={landing} />
            <HowItWorksSection landing={landing} canRegister={canRegister} />
            {s('livemock_enabled') === '1' && <LiveChatMock />}
            <IntegrationsStripSection landing={landing} />
            <WhySection landing={landing} />
            <SecuritySection landing={landing} />
            <PricingSection plans={plans} />
            <TestimonialsSection landing={landing} />
            <FaqSection landing={landing} />
            <CtaSection landing={landing} canRegister={canRegister} />
        </LandingLayout>
    );
}
