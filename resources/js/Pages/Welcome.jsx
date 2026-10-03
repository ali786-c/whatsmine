import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';
import { BrandMark } from '@/Components/BrandIcons';
import Reveal from '@/Components/motion/Reveal';
import Marquee from '@/Components/motion/Marquee';
import FaqAccordion from '@/Components/marketing/FaqAccordion';
import LiveChatMock from '@/Components/marketing/LiveChatMock';
import { useCountUp } from '@/Components/motion/useCountUp';
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

const CHANNEL_STYLES = {
    'whatsapp':  { bg: 'bg-[#25D366]/12', text: 'text-[#25D366]' },
    'messenger': { bg: 'bg-[#0084FF]/12', text: 'text-[#0084FF]' },
    'instagram': { bg: 'bg-[#E1306C]/12', text: 'text-[#E1306C]' },
    'sms':       { bg: 'bg-[#5a8b38]/15', text: 'text-[#467235] dark:text-[#5a8b38]' },
    'email':     { bg: 'bg-[#F59E0B]/12', text: 'text-[#F59E0B]' },
};

// ─── Section Badge ─────────────────────────────────────────────────────────────

function Badge({ text }) {
    if (!text) return null;
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-brand-500/20 text-brand-300 text-xs font-semibold px-3 py-1 border border-brand-500/30">
            <span className="h-1.5 w-1.5 rounded-full bg-brand-400 inline-block" />
            {text}
        </span>
    );
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

            {/* MAIN PRODUCT VISUALIZATION CONTAINER WITH FLOATING CARDS */}
            <div className="mt-14 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
                {/* SVG Connector Lines Overlay for Desktop */}
                <svg className="hidden xl:block absolute inset-0 w-full h-full pointer-events-none z-20" xmlns="http://www.w3.org/2000/svg">
                    {/* Connector 1: Top Left Lead card to Dashboard */}
                    <path d="M 120 70 C 180 70, 200 130, 260 145" fill="none" stroke="#467235" strokeWidth="1.5" strokeDasharray="4 4" strokeOpacity="0.35" />
                    <circle cx="260" cy="145" r="3.5" fill="#467235" fillOpacity="0.8" />

                    {/* Connector 2: Bottom Left Automation Card to Dashboard Sidebar */}
                    <path d="M 150 490 C 200 480, 210 440, 265 425" fill="none" stroke="#283F24" strokeWidth="1.5" strokeDasharray="4 4" strokeOpacity="0.3" />
                    <circle cx="265" cy="425" r="3.5" fill="#283F24" fillOpacity="0.7" />

                    {/* Connector 3: Top Right AI Resolved to Conversation */}
                    <path d="M 1120 75 C 1040 75, 1010 130, 960 145" fill="none" stroke="#FFBF00" strokeWidth="1.5" strokeDasharray="4 4" strokeOpacity="0.5" />
                    <circle cx="960" cy="145" r="3.5" fill="#FFBF00" fillOpacity="0.9" />

                    {/* Connector 4: Bottom Right Order Shipped to Customer Details */}
                    <path d="M 1130 460 C 1060 460, 1040 430, 995 405" fill="none" stroke="#467235" strokeWidth="1.5" strokeDasharray="4 4" strokeOpacity="0.35" />
                    <circle cx="995" cy="405" r="3.5" fill="#467235" fillOpacity="0.8" />
                </svg>

                {/* 5 FLOATING PRODUCT CARDS */}
                {/* Card 1: Top Left - "+1 New Lead" */}
                <div className="hidden lg:flex items-center gap-3 absolute -top-4 -left-2 xl:left-4 z-30 bg-white/95 backdrop-blur-md p-3.5 rounded-2xl shadow-xl shadow-black/5 border border-[#E1EAD5] animate-float-1">
                    <div className="w-10 h-10 rounded-xl bg-[#F0F7E7] border border-[#D7E6C8] flex items-center justify-center text-[#467235]">
                        <svg className="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" />
                        </svg>
                    </div>
                    <div className="text-left">
                        <div className="flex items-center gap-1.5">
                            <span className="text-xs font-bold text-[#0A0A0B]">+1 New Lead</span>
                            <span className="w-1.5 h-1.5 rounded-full bg-[#467235] animate-pulse" />
                        </div>
                        <div className="text-[11px] text-[#55674C] font-medium flex items-center gap-1">
                            <span>Google Maps</span>
                            <span className="text-gray-400">→</span>
                            <span className="font-semibold text-[#283F24]">Imported</span>
                        </div>
                    </div>
                </div>

                {/* Card 2: Top Right - "✓ AI resolved" */}
                <div className="hidden lg:flex items-center gap-3 absolute -top-5 -right-2 xl:right-4 z-30 bg-white/95 backdrop-blur-md p-3.5 rounded-2xl shadow-xl shadow-black/5 border border-[#E1EAD5] animate-float-2">
                    <div className="w-10 h-10 rounded-xl bg-[#FFF9E5] border border-[#FFE7A3] flex items-center justify-center text-[#FFBF00]">
                        <svg className="w-5 h-5 text-[#C48900]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2.5" d="M5 13l4 4L19 7" />
                        </svg>
                    </div>
                    <div className="text-left">
                        <div className="flex items-center gap-1.5">
                            <span className="text-xs font-bold text-[#283F24] flex items-center gap-1">
                                <span className="text-[#3A7828] font-bold">✓</span> AI resolved
                            </span>
                            <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-[#EDF5E3] text-[#345924] font-semibold">0.4s</span>
                        </div>
                        <p className="text-[11px] text-[#55674C] font-medium">Customer query handled automatically</p>
                    </div>
                </div>

                {/* Card 3: Mid-Bottom Left - "Automation running" */}
                <div className="hidden lg:flex items-center gap-3 absolute top-[58%] -left-6 xl:left-2 z-30 bg-white/95 backdrop-blur-md p-3.5 rounded-2xl shadow-xl shadow-black/5 border border-[#E1EAD5] animate-float-3">
                    <div className="w-10 h-10 rounded-xl bg-[#EDF5E4] border border-[#D1E4BE] flex items-center justify-center text-[#467235]">
                        <svg className="w-5 h-5 animate-spin" style={{ animationDuration: '8s' }} fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                        </svg>
                    </div>
                    <div className="text-left">
                        <div className="flex items-center gap-1.5">
                            <span className="text-xs font-bold text-[#0A0A0B]">Automation running</span>
                            <span className="w-2 h-2 rounded-full bg-[#34A853]" />
                        </div>
                        <p className="text-[11px] text-[#55674C] font-medium">Abandoned Cart Recovery ($184 recovered)</p>
                    </div>
                </div>

                {/* Card 4: Mid-Bottom Right - "Order shipped" */}
                <div className="hidden lg:flex items-center gap-3 absolute top-[55%] -right-6 xl:right-2 z-30 bg-white/95 backdrop-blur-md p-3.5 rounded-2xl shadow-xl shadow-black/5 border border-[#E1EAD5] animate-float-4">
                    <div className="w-10 h-10 rounded-xl bg-[#E8F8EC] border border-[#C5EDCE] flex items-center justify-center text-[#25D366]">
                        <svg className="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12.031 6.172c-3.181 0-5.767 2.586-5.768 5.766-.001 1.298.38 2.27 1.019 3.287l-.582 2.128 2.182-.573c.978.58 1.911.928 3.145.929 3.178 0 5.767-2.587 5.768-5.766.001-3.187-2.575-5.77-5.764-5.771zm3.392 8.244c-.144.405-.837.774-1.17.824-.299.045-.677.063-1.092-.069-.252-.08-.575-.187-.988-.365-1.739-.751-2.874-2.502-2.961-2.617-.087-.116-.708-.94-.708-1.793s.448-1.273.607-1.446c.159-.173.346-.217.462-.217l.332.007c.106.005.249-.04.39.299.144.347.491 1.2.534 1.288.043.087.072.188.014.304-.058.116-.087.188-.173.289l-.26.304c-.087.086-.177.18-.076.353.101.173.45 1.008 1.287 1.638.585.438.995.574 1.157.653.162.08.257.07.353-.04.096-.11.411-.479.521-.643.11-.164.22-.138.371-.082.152.055.961.453 1.127.535.166.082.277.123.318.192.041.07.041.405-.103.81z" />
                        </svg>
                    </div>
                    <div className="text-left">
                        <div className="flex items-center gap-1.5">
                            <span className="text-xs font-bold text-[#0A0A0B]">Order shipped #10291</span>
                        </div>
                        <p className="text-[11px] text-[#55674C] font-medium">WhatsApp notification sent · Delivered ✓✓</p>
                    </div>
                </div>

                {/* Card 5: Bottom Floating - "Instagram DM" */}
                <div className="hidden xl:flex items-center gap-3 absolute -bottom-5 right-24 z-30 bg-white/95 backdrop-blur-md py-2.5 px-4 rounded-full shadow-lg shadow-black/5 border border-[#E1EAD5] animate-float-5">
                    <div className="w-6 h-6 rounded-full bg-gradient-to-tr from-[#FD1D1D] via-[#E1306C] to-[#833AB4] flex items-center justify-center text-white">
                        <svg className="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24">
                            <path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z" />
                        </svg>
                    </div>
                    <span className="text-xs font-semibold text-[#0A0A0B]">Instagram DM: <span className="text-[#467235] font-medium">"Do you offer wholesale pricing?"</span></span>
                    <span className="text-[10px] text-gray-400">Just now</span>
                </div>

                {/* MAIN DASHBOARD MOCKUP */}
                <Reveal as="div" origin="bottom" distance={30} duration={900} delay={200}>
                    <div className="relative bg-white rounded-3xl shadow-[0_25px_70px_-15px_rgba(40,63,36,0.15),0_0_0_1px_rgba(70,114,53,0.12)] border border-[#E3EBD7] overflow-hidden">
                        {/* Browser Chrome Header Bar */}
                        <div className="h-11 bg-[#F9FCF5] border-b border-[#E3EBD7] px-4 flex items-center justify-between">
                            <div className="flex items-center gap-2">
                                <div className="flex items-center gap-1.5">
                                    <span className="w-3 h-3 rounded-full bg-[#E35D5B]/70 border border-[#CE4D4B]" />
                                    <span className="w-3 h-3 rounded-full bg-[#E5B543]/70 border border-[#CE9E31]" />
                                    <span className="w-3 h-3 rounded-full bg-[#5FB859]/70 border border-[#4DA047]" />
                                </div>
                                <div className="ml-4 hidden sm:flex items-center gap-1 text-[11px] font-mono text-[#58684F] bg-[#EEF5E6] px-3 py-1 rounded-md border border-[#D9E6CE]">
                                    <svg className="w-3 h-3 text-[#467235]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                                    </svg>
                                    <span>app.whatsmine.com/inbox/unified</span>
                                </div>
                            </div>

                            <div className="flex items-center gap-3">
                                <span className="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[11px] font-medium bg-[#E8F3DE] text-[#315724] border border-[#CFE4BE]">
                                    <span className="w-1.5 h-1.5 rounded-full bg-[#467235]" />
                                    Omnichannel Live
                                </span>
                                <div className="w-6 h-6 rounded-full bg-[#E2EBD6] border border-[#CBDCBE] flex items-center justify-center text-[10px] font-bold text-[#283F24]">
                                    WM
                                </div>
                            </div>
                        </div>

                        {/* Dashboard Layout Grid */}
                        <div className="grid grid-cols-12 min-h-[580px] bg-[#FAFDF7] text-left">
                            {/* 1. LEFT NAVIGATION SIDEBAR */}
                            <aside className="col-span-12 sm:col-span-3 lg:col-span-2 bg-[#F6FAF0] border-r border-[#E3EBD7] p-3 flex flex-col justify-between">
                                <div className="space-y-1">
                                    <div className="px-2 py-2 mb-2 flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <div className="w-6 h-6 rounded-md bg-[#283F24] text-white flex items-center justify-center font-bold text-xs">
                                                W
                                            </div>
                                            <span className="font-space font-bold text-xs tracking-tight text-[#0A0A0B]">Acme Global</span>
                                        </div>
                                        <span className="text-[10px] font-semibold text-[#467235] bg-[#E5F1DB] px-1.5 py-0.5 rounded">Pro</span>
                                    </div>

                                    <nav className="space-y-1">
                                        <a href="#inbox" className="flex items-center justify-between px-2.5 py-2 text-xs font-semibold rounded-lg bg-[#467235] text-white shadow-sm">
                                            <div className="flex items-center gap-2">
                                                <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                                </svg>
                                                <span>Inbox</span>
                                            </div>
                                            <span className="text-[10px] bg-white/25 px-1.5 py-0.2 rounded-full font-mono">14</span>
                                        </a>

                                        <a href="#contacts" className="flex items-center gap-2 px-2.5 py-2 text-xs font-medium rounded-lg text-[#32452B] hover:bg-[#EAF3DE] transition-colors">
                                            <svg className="w-4 h-4 text-[#5D7055]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                                            </svg>
                                            <span>Contacts</span>
                                        </a>

                                        <a href="#automations" className="flex items-center justify-between px-2.5 py-2 text-xs font-medium rounded-lg text-[#32452B] hover:bg-[#EAF3DE] transition-colors">
                                            <div className="flex items-center gap-2">
                                                <svg className="w-4 h-4 text-[#5D7055]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                                </svg>
                                                <span>Automations</span>
                                            </div>
                                            <span className="w-1.5 h-1.5 rounded-full bg-[#FFBF00]" />
                                        </a>

                                        <a href="#ai-agents" className="flex items-center justify-between px-2.5 py-2 text-xs font-medium rounded-lg text-[#32452B] hover:bg-[#EAF3DE] transition-colors">
                                            <div className="flex items-center gap-2">
                                                <svg className="w-4 h-4 text-[#5D7055]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                                </svg>
                                                <span>AI Agents</span>
                                            </div>
                                            <span className="text-[9px] font-bold text-[#FFBF00] bg-[#283F24] px-1 py-0.2 rounded font-mono">✦ AI</span>
                                        </a>

                                        <a href="#campaigns" className="flex items-center gap-2 px-2.5 py-2 text-xs font-medium rounded-lg text-[#32452B] hover:bg-[#EAF3DE] transition-colors">
                                            <svg className="w-4 h-4 text-[#5D7055]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.832c4.1 0 7.625-1.234 9.168-3v14c-1.543-1.766-5.067-3-9.168-3H7a3.988 3.988 0 01-1.564-.317z" />
                                            </svg>
                                            <span>Campaigns</span>
                                        </a>

                                        <a href="#leads" className="flex items-center justify-between px-2.5 py-2 text-xs font-medium rounded-lg text-[#32452B] hover:bg-[#EAF3DE] transition-colors">
                                            <div className="flex items-center gap-2">
                                                <svg className="w-4 h-4 text-[#5D7055]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                </svg>
                                                <span>Leads</span>
                                            </div>
                                            <span className="text-[9px] font-bold text-[#34A853] bg-[#E1F3E1] px-1 rounded">+3</span>
                                        </a>

                                        <a href="#analytics" className="flex items-center gap-2 px-2.5 py-2 text-xs font-medium rounded-lg text-[#32452B] hover:bg-[#EAF3DE] transition-colors">
                                            <svg className="w-4 h-4 text-[#5D7055]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                                            </svg>
                                            <span>Analytics</span>
                                        </a>
                                    </nav>
                                </div>

                                <div className="pt-3 border-t border-[#E3EBD7] px-2">
                                    <div className="flex items-center gap-2">
                                        <div className="w-7 h-7 rounded-full bg-[#E5ECE0] text-[#283F24] flex items-center justify-center font-bold text-xs border border-[#CCDBC4]">
                                            SJ
                                        </div>
                                        <div className="truncate">
                                            <p className="text-xs font-semibold text-[#0A0A0B] truncate">Sarah Jenkins</p>
                                            <p className="text-[10px] text-[#617459] truncate">Lead Admin</p>
                                        </div>
                                    </div>
                                </div>
                            </aside>

                            {/* 2. CONVERSATION LIST */}
                            <div className="col-span-12 sm:col-span-4 lg:col-span-3 border-r border-[#E3EBD7] bg-white flex flex-col">
                                <div className="p-3 border-b border-[#EAEFE2]">
                                    <div className="relative">
                                        <input type="text" placeholder="Search conversations..." className="w-full pl-8 pr-3 py-1.5 text-xs bg-[#F7FAF2] border border-[#E1EAD6] rounded-lg text-gray-800 placeholder-gray-400 focus:outline-none focus:border-[#467235]" />
                                        <svg className="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                                        </svg>
                                    </div>

                                    <div className="flex items-center gap-1.5 mt-2.5">
                                        <span className="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-[#283F24] text-white">All (42)</span>
                                        <span className="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[#F0F5E8] text-[#3D5235] hover:bg-[#E3EED5] cursor-pointer">WhatsApp</span>
                                        <span className="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[#F0F5E8] text-[#3D5235] hover:bg-[#E3EED5] cursor-pointer">IG</span>
                                        <span className="text-[10px] font-medium px-2 py-0.5 rounded-full bg-[#F0F5E8] text-[#3D5235] hover:bg-[#E3EED5] cursor-pointer">SMS</span>
                                    </div>
                                </div>

                                <div className="divide-y divide-[#F2F6EC] overflow-y-auto custom-scroll flex-1">
                                    {/* 1. Ahmed Khan (Active) */}
                                    <div className="p-3 bg-[#F2F7EC] border-l-4 border-[#467235] cursor-pointer">
                                        <div className="flex items-start justify-between">
                                            <div className="flex items-center gap-2">
                                                <div className="w-8 h-8 rounded-full bg-[#DCE7D2] text-[#283F24] font-bold text-xs flex items-center justify-center border border-[#BED2AF]">
                                                    AK
                                                </div>
                                                <div>
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="text-xs font-bold text-[#0A0A0B]">Ahmed Khan</span>
                                                        <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-medium bg-[#E0F8E6] text-[#128C7E]">
                                                            WhatsApp
                                                        </span>
                                                    </div>
                                                    <p className="text-[11px] text-[#3A4A33] font-medium truncate max-w-[150px]">Where is my order?</p>
                                                </div>
                                            </div>
                                            <span className="text-[10px] font-mono text-[#55674C]">Just now</span>
                                        </div>
                                        <div className="mt-2 flex items-center gap-1.5">
                                            <span className="text-[9px] font-semibold px-1.5 py-0.2 rounded bg-[#FFEAA7] text-[#7A5500]">Order #10291</span>
                                            <span className="text-[9px] font-medium text-[#467235] flex items-center gap-0.5">
                                                <span className="w-1.5 h-1.5 rounded-full bg-[#467235]" />
                                                AI Handled
                                            </span>
                                        </div>
                                    </div>

                                    {/* 2. Sarah Ali */}
                                    <div className="p-3 hover:bg-[#FAFDF6] transition-colors cursor-pointer">
                                        <div className="flex items-start justify-between">
                                            <div className="flex items-center gap-2">
                                                <div className="w-8 h-8 rounded-full bg-[#FFE5EC] text-[#B81850] font-bold text-xs flex items-center justify-center">
                                                    SA
                                                </div>
                                                <div>
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="text-xs font-bold text-[#0A0A0B]">Sarah Ali</span>
                                                        <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-medium bg-[#FDEDF2] text-[#C13584]">
                                                            Instagram
                                                        </span>
                                                    </div>
                                                    <p className="text-[11px] text-[#697A62] truncate max-w-[150px]">Can I get the catalog PDF?</p>
                                                </div>
                                            </div>
                                            <span className="text-[10px] font-mono text-gray-400">4m</span>
                                        </div>
                                    </div>

                                    {/* 3. Muhammad Usman */}
                                    <div className="p-3 hover:bg-[#FAFDF6] transition-colors cursor-pointer">
                                        <div className="flex items-start justify-between">
                                            <div className="flex items-center gap-2">
                                                <div className="w-8 h-8 rounded-full bg-[#E5F2FF] text-[#0066CC] font-bold text-xs flex items-center justify-center">
                                                    MU
                                                </div>
                                                <div>
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="text-xs font-bold text-[#0A0A0B]">Muhammad Usman</span>
                                                        <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-medium bg-[#E8F3FF] text-[#0066CC]">
                                                            SMS
                                                        </span>
                                                    </div>
                                                    <p className="text-[11px] text-[#697A62] truncate max-w-[150px]">Confirm my appointment for 3 PM</p>
                                                </div>
                                            </div>
                                            <span className="text-[10px] font-mono text-gray-400">18m</span>
                                        </div>
                                    </div>

                                    {/* 4. Zara Malik */}
                                    <div className="p-3 hover:bg-[#FAFDF6] transition-colors cursor-pointer">
                                        <div className="flex items-start justify-between">
                                            <div className="flex items-center gap-2">
                                                <div className="w-8 h-8 rounded-full bg-[#F3E8FF] text-[#6B21A8] font-bold text-xs flex items-center justify-center">
                                                    ZM
                                                </div>
                                                <div>
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="text-xs font-bold text-[#0A0A0B]">Zara Malik</span>
                                                        <span className="inline-flex items-center px-1.5 py-0.2 rounded text-[9px] font-medium bg-[#E0F8E6] text-[#128C7E]">
                                                            WhatsApp
                                                        </span>
                                                    </div>
                                                    <p className="text-[11px] text-[#697A62] truncate max-w-[150px]">Thank you, resolved!</p>
                                                </div>
                                            </div>
                                            <span className="text-[10px] font-mono text-gray-400">1h</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {/* 3. CENTER ACTIVE CONVERSATION */}
                            <div className="col-span-12 sm:col-span-5 lg:col-span-4 bg-[#FCFEFA] flex flex-col justify-between border-r border-[#E3EBD7]">
                                <div className="p-3 border-b border-[#E8EFE1] bg-white flex items-center justify-between">
                                    <div className="flex items-center gap-2.5">
                                        <div className="relative">
                                            <div className="w-8 h-8 rounded-full bg-[#DCE7D2] text-[#283F24] font-bold text-xs flex items-center justify-center border border-[#BED2AF]">
                                                AK
                                            </div>
                                            <span className="w-2.5 h-2.5 rounded-full bg-[#34A853] border-2 border-white absolute -bottom-0.5 -right-0.5" />
                                        </div>
                                        <div>
                                            <div className="flex items-center gap-2">
                                                <span className="text-xs font-bold text-[#0A0A0B]">Ahmed Khan</span>
                                                <span className="px-1.5 py-0.2 rounded text-[9px] font-semibold bg-[#283F24] text-white">VIP</span>
                                            </div>
                                            <p className="text-[10px] text-[#5A6D53]">via WhatsApp (+1 415-***-8821)</p>
                                        </div>
                                    </div>

                                    <div className="flex items-center gap-1.5">
                                        <span className="text-[10px] font-medium text-[#467235] bg-[#EBF4E2] px-2 py-0.5 rounded-full border border-[#D5E7C7] flex items-center gap-1">
                                            <span className="w-1.5 h-1.5 rounded-full bg-[#467235]" />
                                            AI Agent Active
                                        </span>
                                    </div>
                                </div>

                                <div className="p-4 space-y-4 overflow-y-auto flex-1 bg-[radial-gradient(#E8F0DC_1px,transparent_1px)] [background-size:16px_16px]">
                                    <div className="flex justify-center">
                                        <span className="text-[10px] font-mono text-[#6A7C64] bg-white/80 backdrop-blur px-2.5 py-0.5 rounded-full border border-[#E0E9D4]">
                                            Today, 10:42 AM
                                        </span>
                                    </div>

                                    {/* Customer Incoming Message */}
                                    <div className="flex items-end gap-2 max-w-[85%]">
                                        <div className="w-6 h-6 rounded-full bg-[#DCE7D2] text-[#283F24] text-[10px] font-bold flex items-center justify-center shrink-0">
                                            AK
                                        </div>
                                        <div className="bg-white p-3 rounded-2xl rounded-bl-sm shadow-sm border border-[#E2ECD5] text-left">
                                            <p className="text-xs text-[#1A2616] leading-relaxed">Where is my order?</p>
                                            <div className="mt-1 flex items-center justify-end gap-1">
                                                <span className="text-[9px] font-mono text-gray-400">10:42 AM</span>
                                            </div>
                                        </div>
                                    </div>

                                    {/* AI Agent Autonomous Response */}
                                    <div className="flex items-end justify-end gap-2 ml-auto max-w-[90%]">
                                        <div className="bg-[#F0F7E8] p-3 rounded-2xl rounded-br-sm shadow-sm border border-[#D7E8C6] text-left">
                                            <div className="flex items-center gap-1.5 pb-1 mb-1 border-b border-[#E1EED3]">
                                                <span className="text-[#FFBF00] text-xs font-bold">✦</span>
                                                <span className="text-[10px] font-bold text-[#283F24]">WhatsMine AI Support</span>
                                                <span className="text-[9px] text-[#55694D] bg-white/70 px-1.5 py-0.2 rounded font-mono">Order Tracking RAG</span>
                                            </div>

                                            <p className="text-xs text-[#0A0A0B] leading-relaxed">
                                                Your order <span className="font-bold text-[#283F24] bg-white/60 px-1 py-0.5 rounded">#10291</span> has shipped and is expected to arrive tomorrow.
                                            </p>

                                            <div className="mt-2.5 p-2 bg-white/90 rounded-lg border border-[#D7E8C8] flex items-center justify-between">
                                                <div className="flex items-center gap-2">
                                                    <div className="w-6 h-6 rounded bg-[#E3EED6] flex items-center justify-center text-[#467235]">
                                                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                                                        </svg>
                                                    </div>
                                                    <div className="text-left">
                                                        <p className="text-[10px] font-bold text-[#142311]">Express Courier</p>
                                                        <p className="text-[9px] text-[#5D7353]">Tracking: TRK-99201-US</p>
                                                    </div>
                                                </div>
                                                <span className="text-[9px] font-semibold text-[#467235] bg-[#E9F4DF] px-1.5 py-0.5 rounded">In Transit</span>
                                            </div>

                                            <div className="mt-1.5 flex items-center justify-end gap-1">
                                                <span className="text-[9px] font-mono text-[#55674C]">10:42 AM</span>
                                                <span className="text-[#34B7F1] text-[11px] font-bold leading-none" title="Delivered & Read">✓✓</span>
                                            </div>
                                        </div>

                                        <div className="w-6 h-6 rounded-full bg-[#283F24] text-[#FFBF00] text-[10px] font-bold flex items-center justify-center shrink-0">
                                            ✦
                                        </div>
                                    </div>
                                </div>

                                <div className="p-2.5 bg-white border-t border-[#E3EBD7] flex items-center gap-2">
                                    <button className="p-1.5 rounded-lg text-gray-400 hover:text-gray-600 hover:bg-gray-100">
                                        <svg className="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                        </svg>
                                    </button>
                                    <div className="relative flex-1">
                                        <input type="text" placeholder="AI Agent is actively handling... (Type to take over)" className="w-full text-xs py-1.5 pl-3 pr-8 rounded-lg bg-[#FAFDF6] border border-[#E2ECD5] text-[#283F24] placeholder:text-gray-400 focus:outline-none focus:border-[#467235]" />
                                    </div>
                                    <button className="p-1.5 rounded-lg bg-[#467235] text-white hover:bg-[#395D2A]">
                                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            {/* 4. RIGHT SIDEBAR: CUSTOMER PROFILE */}
                            <aside className="col-span-12 sm:col-span-12 lg:col-span-3 bg-[#F8FBF4] p-3.5 flex flex-col justify-between">
                                <div className="space-y-4">
                                    <div className="flex items-center gap-3">
                                        <div className="w-11 h-11 rounded-xl bg-[#283F24] text-[#F7FAEC] font-bold text-sm flex items-center justify-center border border-[#3E5C37] shadow-sm">
                                            AK
                                        </div>
                                        <div className="text-left">
                                            <div className="flex items-center gap-1.5">
                                                <h3 className="text-xs font-bold text-[#0A0A0B]">Ahmed Khan</h3>
                                                <span className="text-[9px] font-bold bg-[#FFBF00]/20 text-[#855B00] border border-[#FFBF00]/40 px-1 py-0.2 rounded font-mono">VIP</span>
                                            </div>
                                            <p className="text-[11px] text-[#55674C]">ahmed.k@example.com</p>
                                        </div>
                                    </div>

                                    <div className="p-2.5 rounded-xl bg-[#EAF5E0] border border-[#CFE4BC] flex items-center justify-between">
                                        <div className="flex items-center gap-2">
                                            <span className="flex h-2 w-2 relative">
                                                <span className="animate-ping absolute inline-flex h-full w-full rounded-full bg-[#467235] opacity-75" />
                                                <span className="relative inline-flex rounded-full h-2 w-2 bg-[#467235]" />
                                            </span>
                                            <span className="text-xs font-bold text-[#283F24]">AI resolved</span>
                                        </div>
                                        <span className="text-[10px] font-mono text-[#467235] font-semibold">100% confidence</span>
                                    </div>

                                    <div className="space-y-2 text-left">
                                        <div className="flex items-center justify-between text-xs py-1 border-b border-[#E3EDD7]">
                                            <span className="text-[#5D7055]">Current Order:</span>
                                            <span className="font-bold text-[#283F24] font-mono">#10291</span>
                                        </div>

                                        <div className="flex items-center justify-between text-xs py-1 border-b border-[#E3EDD7]">
                                            <span className="text-[#5D7055]">Assigned:</span>
                                            <span className="font-semibold text-[#0A0A0B] flex items-center gap-1 text-[11px]">
                                                <span className="text-[#FFBF00] font-bold">✦</span> AI Agent (Tier 1)
                                            </span>
                                        </div>

                                        <div className="flex items-center justify-between text-xs py-1 border-b border-[#E3EDD7]">
                                            <span className="text-[#5D7055]">Primary Channel:</span>
                                            <span className="font-medium text-[#128C7E] flex items-center gap-1 text-[11px]">
                                                <span className="w-1.5 h-1.5 rounded-full bg-[#128C7E]" /> WhatsApp
                                            </span>
                                        </div>

                                        <div className="flex items-center justify-between text-xs py-1 border-b border-[#E3EDD7]">
                                            <span className="text-[#5D7055]">Lifetime Value:</span>
                                            <span className="font-bold text-[#0A0A0B] font-mono">$1,840.00</span>
                                        </div>
                                    </div>

                                    <div className="text-left">
                                        <span className="text-[11px] font-semibold text-[#44553D] block mb-1.5">Tags</span>
                                        <div className="flex flex-wrap gap-1">
                                            <span className="text-[10px] font-medium bg-[#E8F1DE] text-[#2F4A23] px-2 py-0.5 rounded-md border border-[#D3E5C2]">High Intent</span>
                                            <span className="text-[10px] font-medium bg-[#E8F1DE] text-[#2F4A23] px-2 py-0.5 rounded-md border border-[#D3E5C2]">Repeat Buyer</span>
                                            <span className="text-[10px] font-medium bg-[#FFF6D4] text-[#7A5B00] px-2 py-0.5 rounded-md border border-[#FFE799]">Early Access</span>
                                        </div>
                                    </div>

                                    <div className="text-left">
                                        <span className="text-[11px] font-semibold text-[#44553D] block mb-1.5">Recent Activity</span>
                                        <div className="space-y-1.5">
                                            <div className="text-[10px] text-[#4A5D44] flex items-start gap-1.5">
                                                <span className="w-1 h-1 rounded-full bg-[#467235] mt-1.5" />
                                                <span>Order shipped notification sent to WhatsApp</span>
                                            </div>
                                            <div className="text-[10px] text-[#4A5D44] flex items-start gap-1.5">
                                                <span className="w-1 h-1 rounded-full bg-[#467235] mt-1.5" />
                                                <span>Clicked tracking link (Express Courier)</span>
                                            </div>
                                            <div className="text-[10px] text-[#4A5D44] flex items-start gap-1.5">
                                                <span className="w-1 h-1 rounded-full bg-[#467235] mt-1.5" />
                                                <span>Query resolved in 0.4s via RAG Knowledge base</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div className="pt-3 border-t border-[#E3EDD7] flex gap-2">
                                    <button className="flex-1 py-1.5 text-[11px] font-semibold bg-white border border-[#D5E3C7] rounded-lg text-[#283F24] hover:bg-[#F2F7EC]">
                                        View Contact
                                    </button>
                                    <button className="flex-1 py-1.5 text-[11px] font-semibold bg-[#467235] text-white rounded-lg hover:bg-[#3B622C]">
                                        Take Over
                                    </button>
                                </div>
                            </aside>
                        </div>
                    </div>
                </Reveal>
            </div>
        </section>
    );
}

// ─── Trusted By Section ───────────────────────────────────────────────────────

function TrustedBySection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('stats_enabled') !== '1') return null;

    const brands = [1, 2, 3, 4, 5, 6].map((i) => s(`stats_${i}_label`)).filter(Boolean);
    if (!brands.length) return null;

    const heading = s('stats_heading', 'Trusted by thousands of businesses across the world');

    return (
        <section className="border-y border-neutral-200 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-900/50 py-6 overflow-hidden">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                {heading && (
                    <p className="text-center text-xs font-medium text-neutral-400 dark:text-neutral-500 uppercase tracking-widest mb-5">
                        {heading}
                    </p>
                )}
                <Marquee speed={35}>
                    {brands.map((brand, idx) => (
                        <span
                            key={idx}
                            className="text-lg font-bold text-neutral-300 dark:text-neutral-600 tracking-tight select-none hover:text-neutral-400 dark:hover:text-neutral-500 transition-colors whitespace-nowrap"
                        >
                            {brand}
                        </span>
                    ))}
                </Marquee>
            </div>
        </section>
    );
}

// ─── Problem / Solution Section ───────────────────────────────────────────────

function ProblemSolutionSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('problems_enabled') !== '1') return null;

    const problems = [1, 2, 3, 4].map((i) => s(`problem_${i}`)).filter(Boolean);
    const solutions = [1, 2, 3, 4].map((i) => s(`solution_${i}`)).filter(Boolean);
    const problemTitle = s('problems_title', 'The Problem');
    const solutionTitle = s('solution_title', 'The Solution');
    const solutionDesc = s('solution_desc', '');

    if (!problems.length && !solutions.length) return null;

    return (
        <section className="py-14 sm:py-20">
            <div className="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="grid gap-6 md:grid-cols-2">
                    {/* Problem */}
                    <div className="rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-8">
                        <h3 className="text-xl font-bold text-neutral-900 dark:text-white mb-6">{problemTitle}</h3>
                        <ul className="space-y-4">
                            {problems.map((item, idx) => (
                                <li key={idx} className="flex items-start gap-3">
                                    <span className="mt-0.5 flex-shrink-0 h-5 w-5 rounded-full bg-red-100 dark:bg-red-900/30 flex items-center justify-center">
                                        <svg className="h-3 w-3 text-red-500" fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </span>
                                    <span className="text-sm text-neutral-600 dark:text-neutral-400">{item}</span>
                                </li>
                            ))}
                        </ul>
                    </div>

                    {/* Solution */}
                    <div
                        className="rounded-2xl p-8"
                        style={{ background: 'linear-gradient(135deg, rgba(118,168,78,0.15) 0%, rgba(118,168,78,0.05) 100%)', border: '1px solid rgba(118,168,78,0.3)' }}
                    >
                        <h3 className="text-xl font-bold text-neutral-900 dark:text-white mb-2">{solutionTitle}</h3>
                        {solutionDesc && (
                            <p className="text-sm text-neutral-600 dark:text-neutral-400 mb-6">{solutionDesc}</p>
                        )}
                        <ul className="space-y-4">
                            {solutions.map((item, idx) => (
                                <li key={idx} className="flex items-start gap-3">
                                    <span className="mt-0.5 flex-shrink-0 h-5 w-5 rounded-full flex items-center justify-center" style={{ background: 'rgba(118,168,78,0.2)' }}>
                                        <svg className="h-3 w-3" style={{ color: '#5a8b38' }} fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    </span>
                                    <span className="text-sm text-neutral-700 dark:text-neutral-300">{item}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    );
}

// ─── Features Section ─────────────────────────────────────────────────────────

function FeaturesSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('features_enabled') !== '1') return null;

    const features = [1, 2, 3, 4, 5, 6, 7, 8, 9].map((i) => ({
        icon: s(`feature_${i}_icon`, 'zap'),
        title: s(`feature_${i}_title`),
        desc: s(`feature_${i}_desc`),
    })).filter((f) => f.title);

    return (
        <section id="features" className="py-16 sm:py-24 bg-neutral-50 dark:bg-neutral-900/30">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('features_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('features_title')}
                    </h2>
                    {s('features_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">
                            {s('features_subtitle')}
                        </p>
                    )}
                </div>

                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {features.map((feat, idx) => (
                        <Reveal key={idx} interval={100} index={idx} origin="bottom" distance={40} duration={800}>
                        <div
                            className="group relative overflow-hidden rounded-2xl border border-neutral-200/80 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-7 transition-all duration-300 hover:-translate-y-1 hover:border-[#5a8b38]/40 hover:shadow-xl hover:shadow-[#5a8b38]/10"
                        >
                            {/* Soft glow that fades in on hover */}
                            <div
                                className="pointer-events-none absolute -right-12 -top-12 h-40 w-40 rounded-full opacity-0 blur-2xl transition-opacity duration-300 group-hover:opacity-100"
                                style={{ background: 'radial-gradient(circle, rgba(118,168,78,0.18) 0%, transparent 70%)' }}
                            />

                            <div className="relative">
                                <div className="mb-5 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-[#5a8b38]/10 text-[#467235] dark:text-[#5a8b38] ring-1 ring-inset ring-[#5a8b38]/15 transition-all duration-300 group-hover:bg-[#5a8b38] group-hover:text-white group-hover:ring-[#5a8b38] group-hover:shadow-lg group-hover:shadow-[#5a8b38]/30">
                                    <FeatureIcon name={feat.icon} className="h-6 w-6" />
                                </div>
                                <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-2 transition-colors group-hover:text-[#467235] dark:group-hover:text-[#5a8b38]">{feat.title}</h3>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{feat.desc}</p>
                            </div>
                        </div>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── How It Works Section ─────────────────────────────────────────────────────

function HowItWorksSection({ landing, canRegister }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    const [lineDrawn, setLineDrawn] = useState(false);
    if (s('howitworks_enabled') !== '1') return null;

    const steps = [1, 2, 3].map((i) => ({
        title: s(`step_${i}_title`),
        desc: s(`step_${i}_desc`),
    })).filter((st) => st.title);

    return (
        <section className="py-16 sm:py-24">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('howitworks_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('howitworks_title')}
                    </h2>
                    {s('howitworks_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">{s('howitworks_subtitle')}</p>
                    )}
                </div>

                <Reveal as="div" className="relative" origin="bottom" distance={40} onVisible={() => setLineDrawn(true)}>
                    <div className={`wm-draw-line hidden lg:block absolute top-12 left-1/6 right-1/6 h-0.5 bg-gradient-to-r from-[#5a8b38]/20 via-[#5a8b38]/50 to-[#5a8b38]/20 ${lineDrawn ? 'is-drawn' : ''}`} />
                    <div className="grid gap-10 lg:grid-cols-3">
                        {steps.map((step, idx) => (
                            <div key={idx} className="relative text-center">
                                <div
                                    className="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-full font-bold text-xl shadow-lg ring-4 ring-white dark:ring-neutral-950 text-white"
                                    style={{ background: '#5a8b38', boxShadow: '0 8px 24px rgba(118,168,78,0.3)' }}
                                >
                                    {idx + 1}
                                </div>
                                <h3 className="text-lg font-semibold text-neutral-900 dark:text-white mb-2">{step.title}</h3>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed max-w-xs mx-auto">{step.desc}</p>
                            </div>
                        ))}
                    </div>
                </Reveal>

                {canRegister && (
                    <div className="mt-14 text-center">
                        <Link
                            href={route('register')}
                            className="inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90"
                            style={{ background: '#5a8b38' }}
                        >
                            {t('welcome.get_started_free')}
                            <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                        </Link>
                    </div>
                )}
            </div>
        </section>
    );
}

// ─── Pricing Section ──────────────────────────────────────────────────────────

function PricingSection({ plans }) {
    const { t } = useTranslation();
    const [yearly, setYearly] = useState(false);
    const toggleRef = useRef(null);
    const [pill, setPill] = useState({ left: 0, top: 0, width: 0, height: 0 });

    // Sliding-pill: measure the active toggle button so the highlight slides
    // between Monthly/Yearly even though their widths differ.
    useLayoutEffect(() => {
        const container = toggleRef.current;
        if (!container) return;
        const active = container.querySelectorAll('button')[yearly ? 1 : 0];
        if (active) {
            setPill({
                left: active.offsetLeft,
                top: active.offsetTop,
                width: active.offsetWidth,
                height: active.offsetHeight,
            });
        }
    }, [yearly, plans]);

    if (!plans || !plans.length) return null;

    return (
        <section id="pricing" className="py-16 sm:py-24 bg-neutral-50 dark:bg-neutral-900/30">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-12">
                    <Badge text={t('welcome.badge_pricing')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {t('pricing.simple_transparent')}
                    </h2>
                    <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">
                        {t('pricing.choose_plan_anytime')}
                    </p>

                    <div ref={toggleRef} className="relative mt-8 inline-flex items-center gap-1 rounded-xl bg-neutral-100 dark:bg-neutral-800 p-1">
                        <span
                            aria-hidden="true"
                            className="absolute rounded-lg bg-white dark:bg-neutral-700 shadow-sm transition-all duration-300 ease-out"
                            style={{ left: pill.left, top: pill.top, width: pill.width, height: pill.height }}
                        />
                        <button
                            onClick={() => setYearly(false)}
                            className={`relative z-10 rounded-lg px-5 py-2 text-sm font-semibold transition-colors ${!yearly ? 'text-neutral-900 dark:text-white' : 'text-neutral-500 dark:text-neutral-400'}`}
                        >
                            {t('welcome.monthly')}
                        </button>
                        <button
                            onClick={() => setYearly(true)}
                            className={`relative z-10 rounded-lg px-5 py-2 text-sm font-semibold transition-colors ${yearly ? 'text-neutral-900 dark:text-white' : 'text-neutral-500 dark:text-neutral-400'}`}
                        >
                            {t('welcome.yearly')}
                            <span className="ml-1.5 rounded-full bg-[#5a8b38]/20 text-[#467235] dark:text-[#5a8b38] text-xs px-1.5 py-0.5 font-bold">-20%</span>
                        </button>
                    </div>
                </div>

                <div className={`grid gap-6 ${plans.length <= 2 ? 'sm:grid-cols-2 max-w-2xl mx-auto' : 'sm:grid-cols-2 lg:grid-cols-3'}`}>
                    {plans.map((plan, planIdx) => {
                        const price = yearly ? plan.price_yearly : plan.price_monthly;
                        return (
                            <Reveal key={plan.id} interval={100} index={planIdx} origin="bottom" distance={40} duration={800} className="h-full">
                            <div
                                className={`relative rounded-2xl border p-7 flex flex-col h-full ${
                                    plan.is_featured
                                        ? 'border-[#5a8b38]/50 bg-[#162610] text-white shadow-2xl shadow-[#5a8b38]/10 lg:scale-105'
                                        : 'border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900'
                                }`}
                            >
                                {plan.is_featured && (
                                    <div className="absolute -top-3.5 left-1/2 -translate-x-1/2">
                                        <span className="rounded-full text-xs font-bold px-3 py-1 shadow" style={{ background: '#5a8b38', color: '#ffffff' }}>{t('welcome.most_popular')}</span>
                                    </div>
                                )}

                                <div>
                                    <h3 className={`text-lg font-bold ${plan.is_featured ? 'text-white' : 'text-neutral-900 dark:text-white'}`}>{plan.name}</h3>
                                    {plan.description && (
                                        <p className={`mt-1 text-sm ${plan.is_featured ? 'text-neutral-400' : 'text-neutral-500 dark:text-neutral-400'}`}>{plan.description}</p>
                                    )}
                                </div>

                                <div className="mt-5 mb-6">
                                    <span className={`text-4xl font-bold ${plan.is_featured ? 'text-white' : 'text-neutral-900 dark:text-white'}`}>
                                        {price === 0 ? t('welcome.free') : `$${parseFloat(price).toFixed(0)}`}
                                    </span>
                                    {price > 0 && (
                                        <span className={`text-sm ml-1 ${plan.is_featured ? 'text-neutral-400' : 'text-neutral-400'}`}>{yearly ? t('welcome.per_year') : t('welcome.per_month')}</span>
                                    )}
                                    {plan.trial_days > 0 && (
                                        <p className={`text-xs mt-1 ${plan.is_featured ? 'text-neutral-400' : 'text-neutral-400 dark:text-neutral-500'}`}>
                                            {t('pricing.trial_days', { days: plan.trial_days })}
                                        </p>
                                    )}
                                </div>

                                {plan.features && plan.features.length > 0 && (
                                    <ul className="space-y-2.5 flex-1 mb-7">
                                        {plan.features.map((feat, fi) => (
                                            <li key={fi} className="flex items-start gap-2.5 text-sm">
                                                <svg className="h-4 w-4 mt-0.5 flex-shrink-0" style={{ color: '#5a8b38' }} fill="none" stroke="currentColor" strokeWidth={2.5} viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                                </svg>
                                                <span className={plan.is_featured ? 'text-neutral-300' : 'text-neutral-700 dark:text-neutral-300'}>{feat}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}

                                <Link
                                    href={route('register')}
                                    className={`block text-center rounded-xl py-3 text-sm font-bold transition-all duration-200 ${
                                        plan.is_featured
                                            ? 'text-white hover:opacity-90'
                                            : 'border border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-[#5a8b38]/50 hover:text-[#467235] dark:hover:text-[#5a8b38]'
                                    }`}
                                    style={plan.is_featured ? { background: '#5a8b38' } : {}}
                                >
                                    {plan.is_featured ? t('welcome.get_started_plan') : t('welcome.upgrade')}
                                </Link>
                            </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

// ─── Why Section ──────────────────────────────────────────────────────────────

function WhySection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('why_enabled') !== '1') return null;

    const items = [1, 2, 3, 4, 5, 6].map((i) => ({
        icon: s(`why_${i}_icon`, 'zap'),
        title: s(`why_${i}_title`),
        desc: s(`why_${i}_desc`),
    })).filter((f) => f.title);

    if (!items.length) return null;

    return (
        <section className="py-16 sm:py-24">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('why_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('why_title')}
                    </h2>
                    {s('why_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">
                            {s('why_subtitle')}
                        </p>
                    )}
                </div>

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {items.map((item, idx) => (
                        <Reveal key={idx} interval={100} index={idx} origin="bottom" distance={40} duration={800}>
                        <div className="flex gap-4 p-6 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 hover:border-[#5a8b38]/30 transition-colors">
                            <div className="flex-shrink-0 h-11 w-11 rounded-xl flex items-center justify-center" style={{ background: 'rgba(118,168,78,0.12)' }}>
                                <FeatureIcon name={item.icon} className="h-5 w-5" style={{ color: '#5a8b38' }} />
                            </div>
                            <div>
                                <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-1">{item.title}</h3>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{item.desc}</p>
                            </div>
                        </div>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
    );
}

// ─── Testimonials Section ─────────────────────────────────────────────────────

function StarRating() {
    return (
        <div className="flex gap-0.5">
            {[...Array(5)].map((_, i) => (
                <svg key={i} className="h-4 w-4 text-amber-400" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                </svg>
            ))}
        </div>
    );
}

function TestimonialsSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('testimonials_enabled') !== '1') return null;

    const testimonials = [1, 2, 3, 4, 5, 6].map((i) => ({
        name: s(`testimonial_${i}_name`),
        role: s(`testimonial_${i}_role`),
        text: s(`testimonial_${i}_text`),
        avatar: s(`testimonial_${i}_avatar`),
    })).filter((t) => t.name && t.text);

    if (!testimonials.length) return null;

    return (
        <TestimonialsCarousel testimonials={testimonials} title={s('testimonials_title')} badge={s('testimonials_badge')} subtitle={s('testimonials_subtitle')} />
    );
}

function TestimonialsCarousel({ testimonials, title, badge, subtitle }) {
    const containerRef = useRef(null);

    // Autoplay: advance one card every 5s until the visitor interacts
    // (paused on hover/focus/touch, and skipped under reduced-motion).
    useEffect(() => {
        const el = containerRef.current;
        if (!el) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        let paused = false;
        const pause = () => { paused = true; };
        const resume = () => { paused = false; };
        el.addEventListener('pointerenter', pause);
        el.addEventListener('pointerleave', resume);
        el.addEventListener('focusin', pause);
        el.addEventListener('focusout', resume);
        el.addEventListener('touchstart', pause, { passive: true });

        const id = setInterval(() => {
            if (paused) return;
            const max = el.scrollWidth - el.clientWidth - 4;
            if (el.scrollLeft >= max) {
                el.scrollTo({ left: 0, behavior: 'smooth' });
            } else {
                const card = el.querySelector('[data-tm-card]');
                const step = card ? card.getBoundingClientRect().width + 20 : el.clientWidth * 0.8;
                el.scrollBy({ left: step, behavior: 'smooth' });
            }
        }, 5000);

        return () => {
            clearInterval(id);
            el.removeEventListener('pointerenter', pause);
            el.removeEventListener('pointerleave', resume);
            el.removeEventListener('focusin', pause);
            el.removeEventListener('focusout', resume);
            el.removeEventListener('touchstart', pause);
        };
    }, []);

    const scrollByCard = (dir) => {
        const el = containerRef.current;
        if (!el) return;
        const card = el.querySelector('[data-tm-card]');
        const step = card ? card.getBoundingClientRect().width + 20 : el.clientWidth * 0.8;
        el.scrollBy({ left: dir * step, behavior: 'smooth' });
    };

    return (
        <section className="py-16 sm:py-24 bg-neutral-50 dark:bg-neutral-900/30">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={badge} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {title}
                    </h2>
                    {subtitle && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">{subtitle}</p>
                    )}
                </div>

                <div className="relative group">
                    <div
                        ref={containerRef}
                        data-tm-track
                        className="flex gap-5 overflow-x-auto snap-x snap-mandatory wm-no-scrollbar pb-2 -mx-4 px-4 sm:mx-0 sm:px-0"
                    >
                        {testimonials.map((t, idx) => (
                            <div
                                key={idx}
                                data-tm-card
                                className="snap-start shrink-0 w-[85vw] sm:w-[calc(50%-10px)] lg:w-[calc(33.333%-14px)] rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-6 flex flex-col gap-4"
                            >
                                <StarRating />
                                <blockquote className="text-sm text-neutral-700 dark:text-neutral-300 leading-relaxed flex-1">
                                    &ldquo;{t.text}&rdquo;
                                </blockquote>
                                <div className="flex items-center gap-3 pt-2 border-t border-neutral-100 dark:border-neutral-800">
                                    {t.avatar ? (
                                        <img src={t.avatar} alt={t.name} className="h-9 w-9 rounded-full object-cover" />
                                    ) : (
                                        <div className="h-9 w-9 rounded-full flex items-center justify-center text-white text-sm font-bold flex-shrink-0" style={{ background: '#5a8b38' }}>
                                            {t.name.charAt(0)}
                                        </div>
                                    )}
                                    <div>
                                        <p className="text-sm font-semibold text-neutral-900 dark:text-white">{t.name}</p>
                                        <p className="text-xs text-neutral-500 dark:text-neutral-400">{t.role}</p>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>

                    {/* Prev / next */}
                    <button
                        type="button"
                        aria-label="Previous testimonial"
                        onClick={() => scrollByCard(-1)}
                        className="hidden sm:flex absolute -left-4 top-1/2 -translate-y-1/2 h-10 w-10 items-center justify-center rounded-full bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700 shadow-md text-neutral-600 dark:text-neutral-300 hover:text-[#5a8b38] hover:border-[#5a8b38]/50 transition opacity-0 group-hover:opacity-100 focus:opacity-100"
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M15 19l-7-7 7-7" /></svg>
                    </button>
                    <button
                        type="button"
                        aria-label="Next testimonial"
                        onClick={() => scrollByCard(1)}
                        className="hidden sm:flex absolute -right-4 top-1/2 -translate-y-1/2 h-10 w-10 items-center justify-center rounded-full bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700 shadow-md text-neutral-600 dark:text-neutral-300 hover:text-[#5a8b38] hover:border-[#5a8b38]/50 transition opacity-0 group-hover:opacity-100 focus:opacity-100"
                    >
                        <svg className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M9 5l7 7-7 7" /></svg>
                    </button>
                </div>
            </div>
        </section>
    );
}

// ─── FAQ Section ──────────────────────────────────────────────────────────────

function FaqSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('faq_enabled') !== '1') return null;

    const faqs = [1, 2, 3, 4, 5].map((i) => ({
        q: s(`faq_${i}_q`),
        a: s(`faq_${i}_a`),
    })).filter((f) => f.q && f.a);

    if (!faqs.length) return null;

    return (
        <section id="faq" className="py-16 sm:py-24">
            <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-14">
                    <Badge text={s('faq_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('faq_title')}
                    </h2>
                    {s('faq_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400">{s('faq_subtitle')}</p>
                    )}
                </div>

                <FaqAccordion faqs={faqs} />
            </div>
        </section>
    );
}

// ─── CTA Section ──────────────────────────────────────────────────────────────

function CtaSection({ landing, canRegister }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('cta_enabled') !== '1') return null;

    return (
        <section className="py-14 sm:py-20">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">                    <Reveal origin="bottom" distance={40} duration={900}>
                    <div
                        className="relative overflow-hidden rounded-3xl px-6 py-12 sm:px-8 sm:py-16 text-center"
                    style={{
                        background: 'radial-gradient(ellipse 60% 80% at 50% 50%, rgba(118,168,78,0.15) 0%, transparent 70%), #162610',
                        border: '1px solid rgba(118,168,78,0.2)',
                    }}
                >
                    {/* Grid overlay */}
                    <div
                        className="pointer-events-none absolute inset-0 rounded-3xl"
                        style={{
                            backgroundImage: `
                                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px)
                            `,
                            backgroundSize: '50px 50px',
                        }}
                    />

                    <h2 className="relative text-3xl sm:text-4xl font-bold text-white tracking-tight max-w-2xl mx-auto">
                        {s('cta_title')}
                    </h2>
                    <p className="relative mt-4 text-lg text-neutral-400 max-w-xl mx-auto">
                        {s('cta_subtitle')}
                    </p>

                    <div className="relative mt-10 flex flex-wrap items-center justify-center gap-4">
                        {canRegister && s('cta_primary') && (
                            <Link
                                href={route('register')}
                                className="wm-shimmer inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90"
                                style={{ background: '#5a8b38' }}
                            >
                                {s('cta_primary')}
                                <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                            </Link>
                        )}
                        {s('cta_secondary') && (
                            <Link
                                href={route('contact')}
                                className="inline-flex items-center gap-2 rounded-xl border border-white/20 bg-white/8 text-white px-7 py-3.5 text-base font-semibold hover:bg-white/15 backdrop-blur-sm transition-all duration-200"
                            >
                                {s('cta_secondary')}
                            </Link>
                        )}
                    </div>
                </div>
                    </Reveal>
            </div>
        </section>
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
            <p className="mt-1 text-sm text-neutral-400">{metric.label}</p>
        </Reveal>
    );
}

// ─── Channels Section ─────────────────────────────────────────────────────────

function ChannelsSection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('channels_enabled') !== '1') return null;

    const channels = [1, 2, 3, 4, 5].map((i) => ({
        key: s(`channel_${i}_key`, 'email'),
        title: s(`channel_${i}_title`),
        desc: s(`channel_${i}_desc`),
    })).filter((c) => c.title);

    if (!channels.length) return null;

    return (
        <section className="py-16 sm:py-24">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('channels_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('channels_title')}
                    </h2>
                    {s('channels_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">{s('channels_subtitle')}</p>
                    )}
                </div>

                <div className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {channels.map((c, idx) => {
                        const style = CHANNEL_STYLES[c.key] || CHANNEL_STYLES['email'];
                        return (
                            <Reveal key={idx} interval={100} index={idx} origin="bottom" distance={40} duration={800}>
                            <div
                                className="group rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900 p-6 hover:shadow-lg transition-all duration-300"
                            >
                                <div className={`mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl ${style.bg} ${style.text}`}>
                                    <ChannelIcon name={c.key} className="h-6 w-6" />
                                </div>
                                <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-2">{c.title}</h3>
                                <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{c.desc}</p>
                            </div>
                            </Reveal>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

// ─── Integrations Strip Section ───────────────────────────────────────────────

function IntegrationsStripSection({ landing }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('integrations_strip_enabled') !== '1') return null;

    // Pull the integration names from the integrations-page settings to show a strip.
    const names = [1, 2, 3, 4, 5, 6]
        .flatMap((i) => (s(`intcat_${i}_items`) || '').split('\n'))
        .map((n) => n.trim())
        .filter(Boolean)
        .slice(0, 12);

    return (
        <section className="py-14 sm:py-20 bg-neutral-50 dark:bg-neutral-900/30 border-y border-neutral-200 dark:border-neutral-800">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                <h2 className="text-2xl sm:text-3xl font-bold text-neutral-900 dark:text-white tracking-tight">
                    {s('integrations_strip_title')}
                </h2>
                {s('integrations_strip_subtitle') && (
                    <p className="mt-3 text-base text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">{s('integrations_strip_subtitle')}</p>
                )}

                {names.length > 0 && (
                    <div className="mt-10 flex flex-wrap items-center justify-center gap-3">
                        {names.map((name, idx) => (
                            <span
                                key={idx}
                                className="inline-flex items-center gap-2 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-4 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300"
                            >
                                <BrandMark name={name} tileClassName="h-6 w-6 rounded-md" glyphClassName="h-4 w-4" monogramClassName="text-[0.65rem]" />
                                {name}
                            </span>
                        ))}
                    </div>
                )}

                <div className="mt-10">
                    <Link href="/integrations" className="inline-flex items-center gap-2 text-sm font-semibold text-[#467235] dark:text-[#5a8b38] hover:underline">
                        {t('welcome.view_all_integrations', { defaultValue: 'View all integrations' })}
                        <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={2} viewBox="0 0 24 24"><path strokeLinecap="round" strokeLinejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    </Link>
                </div>
            </div>
        </section>
    );
}

// ─── Security Section ─────────────────────────────────────────────────────────

function SecuritySection({ landing }) {
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    if (s('security_enabled') !== '1') return null;

    const items = [1, 2, 3, 4].map((i) => ({
        icon: s(`security_${i}_icon`, 'shield-check'),
        title: s(`security_${i}_title`),
        desc: s(`security_${i}_desc`),
    })).filter((it) => it.title);

    if (!items.length) return null;

    return (
        <section className="py-16 sm:py-24">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-16">
                    <Badge text={s('security_badge')} />
                    <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-neutral-900 dark:text-white tracking-tight">
                        {s('security_title')}
                    </h2>
                    {s('security_subtitle') && (
                        <p className="mt-4 text-lg text-neutral-600 dark:text-neutral-400 max-w-2xl mx-auto">{s('security_subtitle')}</p>
                    )}
                </div>

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    {items.map((item, idx) => (
                        <Reveal key={idx} interval={100} index={idx} origin="bottom" distance={40} duration={800}>
                        <div className="text-center p-6 rounded-2xl border border-neutral-200 dark:border-neutral-800 bg-white dark:bg-neutral-900">
                            <div className="mx-auto mb-4 inline-flex h-12 w-12 items-center justify-center rounded-xl" style={{ background: 'rgba(118,168,78,0.12)' }}>
                                <FeatureIcon name={item.icon} className="h-6 w-6" style={{ color: '#5a8b38' }} />
                            </div>
                            <h3 className="text-base font-semibold text-neutral-900 dark:text-white mb-1">{item.title}</h3>
                            <p className="text-sm text-neutral-600 dark:text-neutral-400 leading-relaxed">{item.desc}</p>
                        </div>
                        </Reveal>
                    ))}
                </div>
            </div>
        </section>
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
            <MetricsSection landing={landing} />
            <TrustedBySection landing={landing} />
            <ChannelsSection landing={landing} />
            <ProblemSolutionSection landing={landing} />
            <FeaturesSection landing={landing} />
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
