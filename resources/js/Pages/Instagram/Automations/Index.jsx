import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Card from '@/Components/ui/Card';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import { Instagram, Plus, Pencil, Trash2, Zap, BarChart2, Link2, FileDown } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const TRIGGER_LABELS = {
    all_comments: 'Every comment',
    keyword: 'Keywords',
    mention_only: 'Mentions',
};

const DELIVERY_LABELS = { link: 'link', file: 'file', text: 'text' };

// Quick-start presets — ?template= is expanded server-side into prefilled
// form values; the wizard opens ready to walk through.
const TEMPLATES = [
    {
        key: 'link',
        title: 'Send a link',
        desc: 'They comment your word → instant DM with the link. No follow needed.',
        icon: Link2,
    },
    {
        key: 'file',
        title: 'Follow-gated file',
        desc: 'They follow you + reply DONE → the file is sent automatically.',
        icon: FileDown,
    },
];

function triggerSummary(automation) {
    if (automation.trigger_type === 'keyword') {
        const kws = automation.keywords ?? [];
        return kws.length > 0 ? `${TRIGGER_LABELS.keyword}: ${kws.join(', ')}` : TRIGGER_LABELS.keyword;
    }
    return TRIGGER_LABELS[automation.trigger_type] ?? automation.trigger_type;
}

export default function InstagramAutomationsIndex({ automations = [], accountsCount = 0 }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    const toggle = (automation) => router.patch(route('client.instagram.automations.toggle', automation.id));
    const destroy = (automation) => {
        if (confirm(`Delete automation "${automation.name}"?`)) {
            router.delete(route('client.instagram.automations.destroy', automation.id));
        }
    };

    return (
        <ClientLayout>
            <Head title="Instagram Automations" />

            <div className="space-y-5">
                {flash.success && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
                        {flash.success}
                    </div>
                )}

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Comment automations</h1>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            {accountsCount > 0
                                ? `Running on ${accountsCount} connected Instagram account${accountsCount === 1 ? '' : 's'}.`
                                : 'Connect an Instagram account first from the Setup page.'}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Link href={route('client.instagram.setup')}>
                            <Button variant="outline"><Instagram className="h-4 w-4" /> Setup</Button>
                        </Link>
                        <Link href={route('client.instagram.logs.index')}>
                            <Button variant="outline"><BarChart2 className="h-4 w-4" /> Logs</Button>
                        </Link>
                        <Link href={route('client.instagram.automations.create')}>
                            <Button><Plus className="h-4 w-4" /> New automation</Button>
                        </Link>
                    </div>
                </div>

                {automations.length === 0 ? (
                    <Card>
                        <div className="py-8 text-center">
                            <Zap className="mx-auto h-10 w-10 text-neutral-300 dark:text-neutral-700" />
                            <p className="mt-3 font-medium">No automations yet</p>
                            <p className="mx-auto mt-1 max-w-md text-sm text-neutral-500 dark:text-neutral-400">
                                Pick a ready-made template below — it takes about a minute.
                            </p>
                            <div className="mx-auto mt-6 grid max-w-2xl gap-3 px-4 sm:grid-cols-2">
                                {TEMPLATES.map((tpl) => (
                                    <Link
                                        key={tpl.key}
                                        href={route('client.instagram.automations.create', { template: tpl.key })}
                                        className="rounded-xl border-2 border-neutral-200 p-4 text-left transition hover:border-brand-500 hover:bg-brand-50/50 dark:border-neutral-800 dark:hover:border-brand-500 dark:hover:bg-brand-950/30"
                                    >
                                        <tpl.icon className="h-5 w-5 text-brand-500" />
                                        <p className="mt-2 text-sm font-semibold">{tpl.title}</p>
                                        <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{tpl.desc}</p>
                                    </Link>
                                ))}
                            </div>
                            <p className="mt-4 text-xs text-neutral-400">
                                or start from scratch with “New automation”.
                            </p>
                        </div>
                    </Card>
                ) : (
                    <Card padding={false}>
                        <div className="divide-y divide-neutral-200 dark:divide-neutral-800">
                            {automations.map((automation) => (
                                <div key={automation.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                    <div className="min-w-0">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">{automation.name}</span>
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${
                                                automation.is_active
                                                    ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300'
                                                    : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400'
                                            }`}>
                                                {automation.is_active ? 'active' : 'paused'}
                                            </span>
                                            {automation.follow_gate && (
                                                <Badge>follow gate</Badge>
                                            )}
                                        </div>
                                        <p className="mt-0.5 truncate text-xs text-neutral-500 dark:text-neutral-400">
                                            @{automation.account?.username ?? '—'}
                                            {' · '}
                                            {automation.media_ids?.length
                                                ? `${automation.media_ids.length} specific post${automation.media_ids.length === 1 ? '' : 's'}`
                                                : 'all posts'}
                                            {' · '}
                                            {triggerSummary(automation)}
                                            {automation.delivery && (
                                                <> → {DELIVERY_LABELS[automation.delivery.type] ?? 'delivery'}</>
                                            )}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-4">
                                        <div className="text-right text-xs text-neutral-500 dark:text-neutral-400">
                                            <p>{automation.triggered_count ?? 0} triggered</p>
                                            <p>{automation.delivered_count ?? 0} delivered</p>
                                        </div>
                                        <Button variant="ghost" size="sm" onClick={() => toggle(automation)}>
                                            {automation.is_active ? t('automation.pause', 'Pause') : t('automation.activate', 'Activate')}
                                        </Button>
                                        <Link href={route('client.instagram.automations.edit', automation.id)}>
                                            <Button variant="ghost" size="sm"><Pencil className="h-4 w-4" /></Button>
                                        </Link>
                                        <Button variant="ghost" size="sm" onClick={() => destroy(automation)}>
                                            <Trash2 className="h-4 w-4 text-red-500" />
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>
                )}
            </div>
        </ClientLayout>
    );
}
