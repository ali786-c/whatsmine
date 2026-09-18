import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ChevronDown, Plus, X } from 'lucide-react';

function NavGroup({ label, items, onClose, autoOpen = false }) {
    // Collapsible groups start open for discoverability. autoOpen re-derives on
    // every render from the current route — a group containing the active item
    // can never be left collapsed by a navigation.
    const [userToggledClosed, setUserToggledClosed] = useState(false);
    // A group holding the active item is forced open (even after a refresh);
    // otherwise the user's last toggle decides.
    const open = autoOpen || !userToggledClosed;
    const setOpen = (value) => {
        setUserToggledClosed(!value);
    };

    return (
        <div className="mb-0.5">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                aria-expanded={open}
                aria-controls={`nav-group-${label.replace(/\s+/g, '-').toLowerCase()}`}
                className="flex w-full items-center justify-between px-3 py-1.5 mt-3 text-[10px] font-bold uppercase tracking-widest text-white/70 hover:text-white transition-colors duration-150 select-none"
            >
                <span>{label}</span>
                <ChevronDown
                    className={[
                        'h-3 w-3 transition-transform duration-200',
                        open ? 'rotate-0' : '-rotate-90',
                    ].join(' ')}
                />
            </button>

            {open && (
                <div id={`nav-group-${label.replace(/\s+/g, '-').toLowerCase()}`} className="mt-0.5 space-y-0.5">
                    {items.map((item, i) => {
                        const isActive =
                            typeof item.active === 'function'
                                ? item.active()
                                : item.route
                                    ? route().current(item.route)
                                    : false;
                        return (
                            <Link
                                key={item.key ?? item.route ?? item.href ?? i}
                                href={item.href ?? (item.route ? route(item.route) : '#')}
                                onClick={onClose}
                                data-nav-active={isActive || undefined}
                                className={[
                                    'group flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150',
                                    isActive
                                        ? 'bg-brand-600 text-white shadow-sm'
                                        : 'text-white/80 hover:bg-white/10 hover:text-white',
                                ].join(' ')}
                            >
                                {item.icon && (
                                    <span className={[
                                        'shrink-0 transition-colors duration-150',
                                        isActive ? 'text-white' : 'text-white/65 group-hover:text-white',
                                    ].join(' ')}>
                                        {item.icon}
                                    </span>
                                )}
                                <span className="truncate">{item.label}</span>
                                {isActive && (
                                    <span className="ml-auto h-1.5 w-1.5 rounded-full bg-white/70 shrink-0" />
                                )}
                            </Link>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

/**
 * Keeps the active nav item visible: after every navigation/refresh the
 * scrollable <nav> scrolls the highlighted link into view (centered when
 * possible). Without this, items deep in the sidebar (e.g. Instagram group)
 * sit below the fold after a page load and the user thinks the menu vanished.
 */
function useAutoScrollActiveNav() {
    const navRef = useRef(null);
    // The effect must run ONLY on navigation (URL change), not on every
    // render. Without the dependency array, any re-render — polling badges,
    // shared-prop updates, Echo events — re-ran scrollIntoView and yanked the
    // user back to the active item after they had scrolled away manually.
    const { url } = usePage();

    useEffect(() => {
        const nav = navRef.current;
        if (!nav) return;

        // Next frame so layout (incl. collapsible groups) has settled.
        const raf = requestAnimationFrame(() => {
            const active = nav.querySelector('[data-nav-active="true"]')
                ?? nav.querySelector('[data-nav-active=""]');

            if (active) {
                active.scrollIntoView({ block: 'nearest', behavior: 'instant' });
            } else {
                nav.scrollTop = 0;
            }
        });

        return () => window.cancelAnimationFrame(raf);
    }, [url]);

    return navRef;
}

export default function Sidebar({
    navItems = [],
    navGroups = [],
    open = false,
    onClose,
    footer,
    title,
    logo,
    showCreateButton = true,
}) {
    const { t } = useTranslation();
    const appName = import.meta.env.VITE_APP_NAME || 'WhatsMine';
    const { branding } = usePage().props;
    const logoUrl = branding?.logo_url;

    // An item is active when its activePattern/route matches the current URL.
    // Detecting ANY active item lets the group stay open across navigations.
    const hasActiveIn = (items = []) => items.some((item) =>
        typeof item.active === 'function' ? item.active() : item.route ? route().current(item.route) : false,
    );

    const content = (
        <aside className="flex h-full w-64 flex-col bg-secondary-900 dark:bg-neutral-900">
            {/* Brand header */}
            <div className="flex h-14 shrink-0 items-center gap-2.5 px-4 border-b border-white/8">
                {logoUrl ? (
                    <img src={logoUrl} alt={appName} className="h-7 max-w-[140px] object-contain" />
                ) : logo ? (
                    logo
                ) : (
                    <img src="/whatsmine-logo-with-title.svg" alt={appName} className="h-10 w-auto max-w-[200px] object-contain" />
                )}
            </div>

            {showCreateButton && (
                <div className="shrink-0 p-3 pb-2 border-b border-white/8">
                    <button
                        type="button"
                        className="flex w-full items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-semibold text-white bg-brand-600 hover:bg-brand-700 transition duration-150"
                    >
                        <Plus className="h-4 w-4" />
                        {t('common.create')}
                    </button>
                </div>
            )}

            <SidebarNav
                navItems={navItems}
                navGroups={navGroups}
                onClose={onClose}
                hasActiveIn={hasActiveIn}
            />

            {footer && (
                <div className="shrink-0 border-t border-white/8 p-3">
                    <div className="text-white/55">
                        {footer}
                    </div>
                </div>
            )}
        </aside>
    );

    return (
        <>
            {/* Desktop: always visible */}
            <div className="hidden lg:fixed lg:inset-y-0 lg:z-20 lg:flex lg:w-64 lg:flex-col lg:left-0 rtl:lg:left-auto rtl:lg:right-0">
                {content}
            </div>

            {/* Mobile: overlay + drawer */}
            {open && (
                <div className="fixed inset-0 z-40 lg:hidden">
                    <div className="fixed inset-0 bg-black/60 backdrop-blur-sm" onClick={onClose} aria-hidden="true" />
                    <div className="fixed inset-y-0 left-0 w-64 shadow-2xl rtl:left-auto rtl:right-0">
                        <button
                            type="button"
                            onClick={onClose}
                            className="absolute top-3 right-3 z-10 flex h-7 w-7 items-center justify-center rounded-full bg-white/10 text-white/70 hover:bg-white/20 transition"
                            aria-label={t('ui.close_menu')}
                        >
                            <X className="h-4 w-4" />
                        </button>
                        {content}
                    </div>
                </div>
            )}
        </>
    );
}

/**
 * The scrollable nav body, split out so the auto-scroll hook owns a single
 * stable <nav> ref across route changes (Inertia swaps page content but the
 * layout — and therefore this component — persists).
 */
function SidebarNav({ navItems, navGroups, onClose, hasActiveIn }) {
    const navRef = useAutoScrollActiveNav();

    return (
        <nav
            ref={navRef}
            className="flex-1 overflow-y-auto px-2 py-2 scrollbar-thin scrollbar-track-transparent scrollbar-thumb-white/10"
        >
            {navGroups.length > 0 &&
                navGroups.map((group, gi) => (
                    <NavGroup
                        // Index-prefixed: group labels are not guaranteed unique
                        // (e.g. two "Account" groups), and a duplicate React key
                        // makes React omit/duplicate siblings, corrupting the
                        // sidebar across SPA navigations.
                        key={`${gi}-${group.key ?? group.label ?? ''}`}
                        label={group.label}
                        items={group.items ?? []}
                        onClose={onClose}
                        autoOpen={hasActiveIn(group.items ?? [])}
                    />
                ))}

            {navGroups.length === 0 &&
                navItems.map((item, i) => {
                    if (item.type === 'divider') {
                        return <hr key={`div-${i}`} className="my-2 border-white/10" />;
                    }
                    const isActive =
                        typeof item.active === 'function'
                            ? item.active()
                            : item.active ?? (item.route && route().current(item.route));
                    return (
                        <Link
                            key={item.key ?? item.route ?? item.href ?? i}
                            href={item.href ?? (item.route ? route(item.route) : '#')}
                            onClick={onClose}
                            data-nav-active={isActive || undefined}
                            className={[
                                'group flex items-center gap-2.5 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-150',
                                isActive
                                    ? 'bg-brand-600 text-white'
                                    : 'text-white/80 hover:bg-white/10 hover:text-white',
                            ].join(' ')}
                        >
                            {item.icon && (
                                <span className={isActive ? 'text-white' : 'text-white/65 group-hover:text-white'}>
                                    {item.icon}
                                </span>
                            )}
                            <span className="truncate">{item.label}</span>
                        </Link>
                    );
                })}
        </nav>
    );
}
