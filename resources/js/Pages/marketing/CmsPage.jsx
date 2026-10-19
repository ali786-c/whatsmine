import { Head } from '@inertiajs/react';

export default function CmsPage({ page }) {
    return (
        <div className="min-h-screen bg-[#FAFDF7] text-[#0A0A0B]">
            <Head>
                <title>{page.meta_title ?? page.title}</title>
                {page.meta_description && <meta name="description" content={page.meta_description} />}
            </Head>

            <header className="sticky top-0 z-30 border-b border-[#E2EBD9] bg-white/80 backdrop-blur px-6 py-4">
                <a href="/" className="font-bold text-lg text-brand-600">
                    {import.meta.env.VITE_APP_NAME ?? 'wa.careerinpak.com'}
                </a>
            </header>

            <main className="max-w-3xl mx-auto px-4 py-16">
                <h1 className="text-3xl font-bold text-[#0F1A0C] mb-8">{page.title}</h1>
                <div
                    className="prose max-w-none"
                    dangerouslySetInnerHTML={{ __html: page.content ?? '' }}
                />
            </main>
        </div>
    );
}
