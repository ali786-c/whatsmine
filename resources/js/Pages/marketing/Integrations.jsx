import { Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import SeoHead from '@/Components/SeoHead';
import PageHero from '@/Components/marketing/PageHero';
import { BrandMark } from '@/Components/BrandIcons';
import { useTranslation } from 'react-i18next';

function Badge({ text }) {
    if (!text) return null;
    return (
        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#5a8b38]/15 text-[#5a8b38] text-xs font-semibold px-3 py-1 border border-[#5a8b38]/30">
            <span className="h-1.5 w-1.5 rounded-full bg-[#5a8b38] inline-block" />
            {text}
        </span>
    );
}

export default function Integrations({ canRegister, landing = {} }) {
    const { t } = useTranslation();
    const appName = import.meta.env.VITE_APP_NAME || 'wa.careerinpak.com';
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;

    const categories = [1, 2, 3, 4, 5, 6, 7].map((i) => ({
        title: s(`intcat_${i}_title`),
        items: (s(`intcat_${i}_items`) || '').split('\n').map((x) => x.trim()).filter(Boolean),
    })).filter((c) => c.title && c.items.length);

    return (
        <LandingLayout>
            <SeoHead
                title={`${s('integrations_page_title') || t('nav.integrations', { defaultValue: 'Integrations' })} — ${appName}`}
                description={s('integrations_page_subtitle')}
            />

            {/* Hero */}
            <PageHero badge={s('integrations_page_badge')} title={s('integrations_page_title')} subtitle={s('integrations_page_subtitle')} />


            {/* Categories */}
            <section className="py-24">
                <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
                    {categories.map((cat, ci) => (
                        <div key={ci}>
                            <h2 className="text-xl font-bold text-[#0F1A0C] tracking-tight mb-5">{cat.title}</h2>
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {cat.items.map((item, ii) => (
                                    <div
                                        key={ii}
                                        className="flex items-center gap-3 rounded-2xl border border-[#E2EBD9] bg-white p-4 hover:border-[#5a8b38]/40 hover:shadow-md transition-all duration-200"
                                    >
                                        <BrandMark name={item} tileClassName="h-10 w-10 rounded-xl flex-shrink-0" glyphClassName="h-6 w-6" />
                                        <span className="text-sm font-medium text-[#0F1A0C]">{item}</span>
                                    </div>
                                ))}
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            {/* CTA */}
            <section className="py-20 bg-[#F2F7EC] border-t border-[#E2EBD9]">
                <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                    <h2 className="text-3xl font-bold text-[#0F1A0C] tracking-tight">
                        {t('integrations_page.cta_title', { defaultValue: 'Need a custom integration?' })}
                    </h2>
                    <p className="mt-4 text-lg text-[#4A5B44]">
                        {t('integrations_page.cta_subtitle', { defaultValue: `Use our REST API and webhooks to connect ${appName} to anything, or talk to our team.` })}
                    </p>
                    <div className="mt-8 flex flex-wrap items-center justify-center gap-4">
                        {canRegister && (
                            <Link href={route('register')} className="inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90" style={{ background: '#5a8b38' }}>
                                {t('welcome.get_started_free', { defaultValue: 'Get Started Free' })}
                            </Link>
                        )}
                        <Link href="/contact" className="inline-flex items-center gap-2 rounded-xl border border-[#E2EBD9] px-7 py-3.5 text-base font-semibold text-[#4A5B44] hover:border-[#5a8b38]/50 transition-all duration-200">
                            {t('nav.contact', { defaultValue: 'Contact Sales' })}
                        </Link>
                    </div>
                </div>
            </section>
        </LandingLayout>
    );
}
