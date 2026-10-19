import { useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Dropdown } from '@/Components/ui';
import { useLocale } from '@/hooks/useLocale';
import ScrollTop from '@/Components/motion/ScrollTop';
import { Globe } from 'lucide-react';

function MenuIcon({ className }) {
    return (
        <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
        </svg>
    );
}

function XIcon({ className }) {
    return (
        <svg className={className} fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
        </svg>
    );
}

export default function LandingLayout({ children }) {
    const { t } = useTranslation();

    const NAV_LINKS = [
        { label: t('nav.features'),     href: '/#features' },
        { label: t('nav.use_cases'),    href: '/use-cases' },
        { label: t('nav.integrations', { defaultValue: 'Integrations' }), href: '/integrations' },
        { label: t('nav.pricing'),      href: '/pricing' },
        { label: t('nav.faq'),          href: '/faq' },
        { label: t('nav.contact'),      href: '/contact' },
    ];
    const page = usePage();
    const auth = page.props.auth;
    const { locale: currentLocale, setLocale } = useLocale();
    const supportedLocales = page.props.supportedLocales ?? { en: 'English' };
    const localeEntries = Object.entries(supportedLocales);
    const appName = page.props.branding?.app_name || import.meta.env.VITE_APP_NAME || 'wa.careerinpak.com';
    const logoUrl = page.props.branding?.logo_url || '/whatsmine-logo-with-title.svg';
    const [mobileOpen, setMobileOpen] = useState(false);
    const [scrolled, setScrolled] = useState(false);
    const [progress, setProgress] = useState(0);
    const landing = page.props.landing ?? {};

    // Header scroll state: compact + blurred once past 100px (reference uses
    // 300px because its header starts non-sticky; ours is always sticky).
    useEffect(() => {
        let raf = null;
        const onScroll = () => {
            if (raf) return;
            raf = requestAnimationFrame(() => {
                setScrolled(window.scrollY > 100);
                const doc = document.documentElement;
                const max = doc.scrollHeight - window.innerHeight;
                setProgress(max > 0 ? Math.min(100, (window.scrollY / max) * 100) : 0);
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

    const signinLabel = landing['landing.signin_label'] || 'Sign In';
    const signinHref = landing['landing.signin_link_type'] === 'static' && landing['landing.signin_link_url']
        ? landing['landing.signin_link_url']
        : route('login');
    const signinIsExternal = landing['landing.signin_link_type'] === 'static' && landing['landing.signin_link_url'];

    const getStartedLabel = landing['landing.getstarted_label'] || 'Get Started';
    const getStartedHref = landing['landing.getstarted_link_type'] === 'static' && landing['landing.getstarted_link_url']
        ? landing['landing.getstarted_link_url']
        : route('register');
    const getStartedIsExternal = landing['landing.getstarted_link_type'] === 'static' && landing['landing.getstarted_link_url'];

        return (
        <div className="min-h-screen bg-[#FAFDF7] text-[#0A0A0B] flex flex-col relative overflow-x-clip">
            {/* Background Grid & Radial Lighting matching Hero Section */}
            <div className="absolute inset-0 bg-hero-grid-pattern pointer-events-none opacity-80 -z-10" />
            <div className="absolute top-24 left-1/2 -translate-x-1/2 w-[1200px] h-[750px] hero-radial-glow pointer-events-none -z-10" />

            {/* ── Header ── */}
            <header
                className={`sticky top-0 z-50 border-b border-white/10 transition-all duration-300 ${
                    scrolled
                        ? 'shadow-lg shadow-black/20 backdrop-blur-md'
                        : 'shadow-none'
                }`}
                style={{ background: scrolled ? 'rgba(22,38,16,0.92)' : '#162610' }}
            >
                <div
                    aria-hidden="true"
                    className="absolute inset-x-0 bottom-0 h-0.5 bg-brand-400/80 transition-[width] duration-150 ease-out"
                    style={{ width: progress + '%' }}
                />
                <div className={`max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between gap-6 transition-all duration-300 ${scrolled ? 'h-14' : 'h-16'}`}>
                    {/* Logo */}
                    <Link href={route('home')} className="flex items-center group flex-shrink-0">
                        <img src={logoUrl} alt={appName} className="h-9 w-auto max-w-[180px] object-contain transition-opacity group-hover:opacity-80" />
                    </Link>

                    {/* Desktop nav links */}
                    <nav className="hidden md:flex items-center gap-1 flex-1">
                        {NAV_LINKS.map((link) => (
                            <Link
                                key={link.href}
                                href={link.href}
                                className="rounded-soft px-3 py-1.5 text-sm text-white/80 hover:text-white hover:bg-white/10 transition duration-150 font-medium"
                            >
                                {link.label}
                            </Link>
                        ))}
                    </nav>

                    {/* Right side */}
                    <div className="flex items-center gap-1">
                        {/* Locale */}
                        {localeEntries.length > 1 && (
                            <Dropdown>
                                <Dropdown.Trigger>
                                    <button
                                        type="button"
                                        className="hidden sm:flex items-center gap-1.5 rounded-soft px-2.5 py-1.5 text-sm text-white/70 hover:text-white hover:bg-white/10 transition duration-150"
                                        aria-label={t('topbar.language')}
                                    >
                                        <Globe className="h-4 w-4" />
                                        <span>{currentLocale.toUpperCase()}</span>
                                        <svg className="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M19 9l-7 7-7-7" />
                                        </svg>
                                    </button>
                                </Dropdown.Trigger>
                                <Dropdown.Content align="right" width="48">
                                    {localeEntries.map(([code, label]) => (
                                        <Dropdown.Item
                                            key={code}
                                            as="button"
                                            onClick={() => setLocale(code)}
                                            className={currentLocale === code ? 'bg-brand-50 text-brand-700 dark:bg-brand-900/30 dark:text-brand-300 font-medium' : ''}
                                        >
                                            {label}
                                        </Dropdown.Item>
                                    ))}
                                </Dropdown.Content>
                            </Dropdown>
                        )}

                        {/* Auth links — desktop */}
                        <div className="hidden sm:flex items-center gap-1 ms-1 ps-2 border-l border-white/20">
                            {auth?.user ? (
                                <>
                                    <Link
                                        href={route('client.dashboard')}
                                        className="rounded-soft px-3 py-1.5 text-sm text-white/80 hover:text-white hover:bg-white/10 transition duration-150"
                                    >
                                        {t('nav.dashboard')}
                                    </Link>
                                    <Link
                                        href={route('logout')}
                                        method="post"
                                        as="button"
                                        className="rounded-soft bg-brand-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-700 shadow-sm transition-all duration-150"
                                    >
                                        {t('nav.sign_out')}
                                    </Link>
                                </>
                            ) : (
                                <>
                                    {signinIsExternal ? (
                                        <a
                                            href={signinHref}
                                            className="rounded-soft px-3 py-1.5 text-sm text-white/80 hover:text-white hover:bg-white/10 transition duration-150 font-medium"
                                        >
                                            {signinLabel}
                                        </a>
                                    ) : (
                                        <Link
                                            href={signinHref}
                                            className="rounded-soft px-3 py-1.5 text-sm text-white/80 hover:text-white hover:bg-white/10 transition duration-150 font-medium"
                                        >
                                            {signinLabel}
                                        </Link>
                                    )}
                                    {getStartedIsExternal ? (
                                        <a
                                            href={getStartedHref}
                                            className="rounded-soft bg-brand-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-700 shadow-sm transition-all duration-150"
                                        >
                                            {getStartedLabel}
                                        </a>
                                    ) : (
                                        <Link
                                            href={getStartedHref}
                                            className="rounded-soft bg-brand-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-brand-700 shadow-sm transition-all duration-150"
                                        >
                                            {getStartedLabel}
                                        </Link>
                                    )}
                                </>
                            )}
                        </div>

                        {/* Mobile hamburger */}
                        <button
                            type="button"
                            className="sm:hidden flex items-center justify-center rounded-soft p-2 text-white/70 hover:text-white hover:bg-white/10 transition duration-150 ms-1"
                            onClick={() => setMobileOpen(!mobileOpen)}
                            aria-label={t('open_menu')}
                        >
                            {mobileOpen ? <XIcon className="h-5 w-5" /> : <MenuIcon className="h-5 w-5" />}
                        </button>
                    </div>
                </div>

            </header>

            {/* Mobile menu — offcanvas drawer (reference behavior). Lives OUTSIDE
                the header: backdrop-blur on the scrolled header would otherwise
                become the containing block for this fixed-position drawer. */}
            <div
                    className={`sm:hidden fixed inset-0 z-[60] transition-opacity duration-300 ${
                        mobileOpen ? 'opacity-100' : 'opacity-0 pointer-events-none'
                    }`}
                    aria-hidden={!mobileOpen}
                >
                    {/* Overlay */}
                    <div
                        className={`absolute inset-0 bg-black/50 transition-opacity duration-300 ${mobileOpen ? 'opacity-100' : 'opacity-0'}`}
                        onClick={() => setMobileOpen(false)}
                    />
                    {/* Drawer */}
                    <nav
                        className={`absolute right-0 top-0 h-full w-72 max-w-[85vw] bg-[#162610] border-l border-white/10 shadow-2xl overflow-y-auto transition-transform duration-300 ease-out ${
                            mobileOpen ? 'translate-x-0' : 'translate-x-full'
                        }`}
                    >
                        <div className="flex items-center justify-between px-4 h-14 border-b border-white/10">
                            <span className="text-sm font-semibold text-white/90">Menu</span>
                            <button
                                type="button"
                                onClick={() => setMobileOpen(false)}
                                aria-label={t('ui.close_menu')}
                                className="flex items-center justify-center rounded-soft p-2 text-white/70 hover:text-white hover:bg-white/10 transition duration-150"
                            >
                                <XIcon className="h-5 w-5" />
                            </button>
                        </div>
                        <div className="px-4 py-4 space-y-1">
                            {NAV_LINKS.map((link) => (
                                <Link
                                    key={link.href}
                                    href={link.href}
                                    onClick={() => setMobileOpen(false)}
                                    className="block rounded-soft px-3 py-2.5 text-sm font-medium text-white/80 hover:text-white hover:bg-white/10 transition"
                                >
                                    {link.label}
                                </Link>
                            ))}
                            <div className="pt-3 border-t border-white/10 space-y-1 mt-3">
                                {auth?.user ? (
                                    <>
                                        <Link href={route('client.dashboard')} onClick={() => setMobileOpen(false)} className="block rounded-soft px-3 py-2.5 text-sm font-medium text-white/80 hover:text-white hover:bg-white/10 transition">
                                            {t('nav.dashboard')}
                                        </Link>
                                        <Link href={route('logout')} method="post" as="button" className="block w-full text-left rounded-soft px-3 py-2.5 text-sm font-medium text-white/80 hover:text-white hover:bg-white/10 transition">
                                            {t('nav.sign_out')}
                                        </Link>
                                    </>
                                ) : (
                                    <>
                                        {signinIsExternal ? (
                                            <a href={signinHref} onClick={() => setMobileOpen(false)} className="block rounded-soft px-3 py-2.5 text-sm font-medium text-white/80 hover:text-white hover:bg-white/10 transition">
                                                {signinLabel}
                                            </a>
                                        ) : (
                                            <Link href={signinHref} onClick={() => setMobileOpen(false)} className="block rounded-soft px-3 py-2.5 text-sm font-medium text-white/80 hover:text-white hover:bg-white/10 transition">
                                                {signinLabel}
                                            </Link>
                                        )}
                                        {getStartedIsExternal ? (
                                            <a href={getStartedHref} onClick={() => setMobileOpen(false)} className="block rounded-soft bg-brand-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 transition text-center">
                                                {getStartedLabel}
                                            </a>
                                        ) : (
                                            <Link href={getStartedHref} onClick={() => setMobileOpen(false)} className="block rounded-soft bg-brand-600 px-3 py-2.5 text-sm font-semibold text-white hover:bg-brand-700 transition text-center">
                                                {getStartedLabel}
                                            </Link>
                                        )}
                                    </>
                                )}
                            </div>
                        </div>
                    </nav>
            </div>

            {/* ── Main content ── */}
            <main className="flex-1">{children}</main>

            <ScrollTop />

            {/* ── Footer ── */}
            <footer className="relative bg-[#162610] text-white">
                <div className="pointer-events-none absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-brand-400/50 to-transparent" />
                <div className="wm-grid-dark pointer-events-none absolute inset-0 opacity-60" />
                <div className="relative z-10 mx-auto max-w-6xl px-4 py-14 sm:px-6 lg:px-8">
                    <div className="mb-12 grid grid-cols-2 gap-8 sm:grid-cols-4">
                        <div className="col-span-2 sm:col-span-1">
                            <Link href={route('home')} className="mb-4 inline-flex items-center">
                                <img src={logoUrl} alt={appName} className="h-9 w-auto max-w-[180px] object-contain" />
                            </Link>
                            <p className="max-w-xs text-sm leading-relaxed text-white/60">{t('landing.footer_tagline')}</p>
                            <span className="mt-5 inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1.5 text-xs font-semibold text-white/70">
                                <span className="relative flex h-2 w-2">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-brand-400 opacity-75" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-brand-400" />
                                </span>
                                All systems operational
                            </span>
                            <div className="mt-5 flex items-center gap-3">
                                {[
                                    { label: 'Twitter', path: 'M8.29 20.251c7.547 0 11.675-6.253 11.675-11.675 0-.178 0-.355-.012-.53A8.348 8.348 0 0022 5.92a8.19 8.19 0 01-2.357.646 4.118 4.118 0 001.804-2.27 8.224 8.224 0 01-2.605.996 4.107 4.107 0 00-6.993 3.743 11.65 11.65 0 01-8.457-4.287 4.106 4.106 0 001.27 5.477A4.072 4.072 0 012.8 9.713v.052a4.105 4.105 0 003.292 4.022 4.095 4.095 0 01-1.853.07 4.108 4.108 0 003.834 2.85A8.233 8.233 0 012 18.407a11.616 11.616 0 006.29 1.84' },
                                    { label: 'Facebook', path: 'M18 2h-3a5 5 0 00-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 011-1h3z' },
                                    { label: 'Instagram', path: 'M16 8a6 6 0 016 6v7h-4v-7a2 2 0 00-2-2 2 2 0 00-2 2v7h-4v-7a6 6 0 016-6zM2 9h4v12H2z M4 6a2 2 0 100-4 2 2 0 000 4z' },
                                ].map(function (soc) {
                                    return (
                                        <a key={soc.label} href="#" aria-label={soc.label} className="flex h-8 w-8 items-center justify-center rounded-lg border border-white/10 bg-white/5 text-white/60 transition hover:border-brand-400/40 hover:text-white">
                                            <svg className="h-4 w-4" fill="none" stroke="currentColor" strokeWidth={1.5} viewBox="0 0 24 24">
                                                <path strokeLinecap="round" strokeLinejoin="round" d={soc.path} />
                                            </svg>
                                        </a>
                                    );
                                })}
                            </div>
                        </div>

                        {[
                            {
                                heading: t('landing_page_admin.footer_company', { defaultValue: 'Company' }),
                                links: [
                                    { label: t('landing_page_admin.footer_about', { defaultValue: 'About' }), href: '/about' },
                                    { label: t('nav.integrations', { defaultValue: 'Integrations' }), href: '/integrations' },
                                    { label: t('nav.use_cases', { defaultValue: 'Use Cases' }), href: '/use-cases' },
                                    { label: t('nav.contact'), href: '/contact' },
                                ],
                            },
                            {
                                heading: t('landing_page_admin.footer_legal', { defaultValue: 'Legal' }),
                                links: [
                                    { label: t('landing_page_admin.footer_privacy', { defaultValue: 'Privacy Policy' }), href: '/p/privacy' },
                                    { label: t('landing_page_admin.footer_terms', { defaultValue: 'Terms of Service' }), href: '/p/terms' },
                                    { label: t('landing_page_admin.footer_cookies', { defaultValue: 'Cookie Policy' }), href: '/p/cookies' },
                                    { label: t('landing_page_admin.footer_gdpr', { defaultValue: 'GDPR' }), href: '/p/gdpr' },
                                ],
                            },
                            {
                                heading: t('landing_page_admin.footer_product', { defaultValue: 'Product' }),
                                links: [
                                    { label: t('nav.features'), href: '/#features' },
                                    { label: t('nav.integrations', { defaultValue: 'Integrations' }), href: '/integrations' },
                                    { label: t('nav.pricing'), href: '/pricing' },
                                    { label: t('nav.faq'), href: '/faq' },
                                ],
                            },
                        ].map(function (col) {
                            return (
                                <div key={col.heading}>
                                    <h4 className="mb-4 text-xs font-bold uppercase tracking-[0.14em] text-brand-300">{col.heading}</h4>
                                    <ul className="space-y-2.5">
                                        {col.links.map(function (l) {
                                            return (
                                                <li key={l.href}>
                                                    <Link href={l.href} className="text-sm text-white/60 transition hover:text-white">{l.label}</Link>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            );
                        })}
                    </div>

                    <div className="flex flex-col items-center justify-between gap-4 border-t border-white/10 pt-6 sm:flex-row">
                        <p className="text-xs text-white/45">{String.fromCharCode(169)} {new Date().getFullYear()} {appName}. {t('nav.all_rights_reserved')}</p>
                                            </div>
                </div>
            </footer>
        </div>
    );
}
