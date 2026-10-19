import { useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import LandingLayout from '@/Layouts/LandingLayout';
import FaqAccordion from '@/Components/marketing/FaqAccordion';
import PageHero from '@/Components/marketing/PageHero';
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

const CATEGORIES = [
    { key: 'all',       labelKey: 'faq.cat_all' },
    { key: 'general',   labelKey: 'faq.cat_general' },
    { key: 'billing',   labelKey: 'faq.cat_billing' },
    { key: 'technical', labelKey: 'faq.cat_technical' },
    { key: 'security',  labelKey: 'faq.cat_security' },
];

export default function Faq({ landing = {}, canRegister }) {
    const { t } = useTranslation();
    const s = (key, def = '') => landing[`landing.${key}`] ?? def;
    const [cat, setCat] = useState('all');

    const faqs = [1, 2, 3, 4, 5].map((i) => ({
        q: s(`faq_${i}_q`),
        a: s(`faq_${i}_a`),
    })).filter((f) => f.q && f.a);

    const title = s('faq_title', 'Frequently Asked Questions');
    const subtitle = s('faq_subtitle', 'Everything you need to know about our platform.');

    return (
        <LandingLayout>
            <Head>
                <title>{t('faq.head_title', { title })}</title>
                <meta name="description" content={subtitle} />
            </Head>

            {/* ── Page hero ── */}
            <PageHero badge={t('nav.faq')} title={title} subtitle={subtitle} />


            {/* ── Category filter ── */}
            <section className="border-b border-[#E2EBD9] bg-[#FAFDF7] sticky top-16 z-10">
                <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 flex items-center gap-2 overflow-x-auto py-3 scrollbar-none">
                    {CATEGORIES.map((c) => (
                        <button
                            key={c.key}
                            onClick={() => setCat(c.key)}
                            className={`flex-shrink-0 rounded-full px-4 py-1.5 text-sm font-medium transition-all ${
                                cat === c.key
                                    ? 'text-white font-bold'
                                    : 'text-[#5B6B52] hover:text-[#0F1A0C] bg-[#F2F7EC]'
                            }`}
                            style={cat === c.key ? { background: '#5a8b38' } : {}}
                        >
                            {t(c.labelKey)}
                        </button>
                    ))}
                </div>
            </section>

            {/* ── FAQ list ── */}
            <section className="py-16">
                <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                    {faqs.length === 0 ? (
                        <p className="text-center text-[#5B6B52] py-16">{t('faq.no_items')}</p>
                    ) : (
                        <FaqAccordion faqs={faqs} />
                    )}

                    {/* Still have questions? */}
                    <div
                        className="mt-12 rounded-2xl p-8 text-center"
                        style={{ background: 'rgba(118,168,78,0.08)', border: '1px solid rgba(118,168,78,0.25)' }}
                    >
                        <h3 className="text-lg font-bold text-[#0F1A0C] mb-2">{t('faq.still_have_questions')}</h3>
                        <p className="text-sm text-[#4A5B44] mb-5">{t('faq.still_have_questions_desc')}</p>
                        <Link
                            href="/contact"
                            className="inline-flex items-center gap-2 rounded-xl px-6 py-2.5 text-sm font-bold text-white transition-all hover:opacity-90"
                            style={{ background: '#5a8b38' }}
                        >
                            {t('faq.contact_support')}
                        </Link>
                    </div>
                </div>
            </section>

            {/* ── CTA ── */}
            {canRegister && (
                <section className="py-16 bg-[#F2F7EC]">
                    <div className="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                        <h2 className="text-2xl font-bold text-[#0F1A0C]">{t('faq.ready_to_try')}</h2>
                        <p className="mt-2 text-[#5B6B52] text-sm">{t('faq.ready_to_try_desc')}</p>
                        <Link
                            href={route('register')}
                            className="mt-6 inline-flex items-center gap-2 rounded-xl px-7 py-3.5 text-base font-bold text-white shadow-lg transition-all duration-200 hover:opacity-90"
                            style={{ background: '#5a8b38' }}
                        >
                            {t('welcome.get_started_free')}
                        </Link>
                    </div>
                </section>
            )}
        </LandingLayout>
    );
}
