import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import Card from '@/Components/ui/Card';
import Button from '@/Components/ui/Button';
import { IgTemplateBody, IgDmPreview } from '@/Components/Instagram/IgTemplatePreview';
import { Plus, Pencil, Trash2, LayoutTemplate } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { igTemplateSummary } from '@/Utils/igTemplate';

export default function InstagramTemplatesIndex({ templates = [] }) {
    const { t } = useTranslation();
    const [deleting, setDeleting] = useState(null);

    const destroy = (tpl) => {
        if (confirm(`Delete template "${tpl.name}"?`)) {
            router.delete(route('client.instagram.templates.destroy', tpl.id), {
                onSuccess: () => setTemplatesSafe(tpl.id),
            });
        }
    };

    const setTemplatesSafe = () => router.reload({ only: ['templates'] });

    return (
        <ClientLayout>
            <Head title="Instagram Templates" />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{t('nav.instagram_templates')}</h1>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            Carousel & button templates for Instagram DMs — compose here, send from the Inbox.
                        </p>
                    </div>
                    <Link href={route('client.instagram.templates.create')}>
                        <Button><Plus className="h-4 w-4" /> New template</Button>
                    </Link>
                </div>

                {templates.length === 0 ? (
                    <Card>
                        <div className="py-10 text-center">
                            <LayoutTemplate className="mx-auto h-10 w-10 text-neutral-300 dark:text-neutral-700" />
                            <p className="mt-3 font-medium">No templates yet</p>
                            <p className="mx-auto mt-1 max-w-md text-sm text-neutral-500 dark:text-neutral-400">
                                Create a carousel or button template with a live preview — it takes about a minute. No Meta approval needed.
                            </p>
                            <div className="mt-5">
                                <Link href={route('client.instagram.templates.create')}>
                                    <Button><Plus className="h-4 w-4" /> Create your first template</Button>
                                </Link>
                            </div>
                        </div>
                    </Card>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {templates.map(tpl => (
                            <Card key={tpl.id} padding={false} className="overflow-hidden flex flex-col">
                                <div className="border-b border-neutral-100 dark:border-neutral-800 bg-neutral-50 dark:bg-neutral-800/50 p-2">
                                    <IgDmPreview
                                        payload={tpl.type === 'button'
                                            ? { template_type: 'button', text: tpl.definition?.text, buttons: tpl.definition?.buttons ?? [] }
                                            : { template_type: 'generic', elements: tpl.definition?.elements ?? [] }}
                                        incomingText="Hi! 👋"
                                    />
                                </div>
                                <div className="flex items-center gap-2 p-3">
                                    <div className="min-w-0 flex-1">
                                        <p className="text-sm font-semibold truncate">{tpl.name}</p>
                                        <p className="text-[11px] text-neutral-400 truncate">
                                            {tpl.type === 'generic' ? t('inbox.ig_tpl_carousel') : t('inbox.ig_tpl_buttons')}
                                            {' · '}{igTemplateSummary(tpl.type, tpl.definition)}
                                        </p>
                                    </div>
                                    <Link href={route('client.instagram.templates.edit', tpl.id)}>
                                        <Button variant="ghost" size="sm"><Pencil className="h-4 w-4" /></Button>
                                    </Link>
                                    <Button variant="ghost" size="sm" onClick={() => destroy(tpl)}>
                                        <Trash2 className="h-4 w-4 text-red-500" />
                                    </Button>
                                </div>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}
