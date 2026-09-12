import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Card from '@/Components/ui/Card';
import Button from '@/Components/ui/Button';
import Badge from '@/Components/ui/Badge';
import { Instagram, Plus, Pencil, Trash2, Zap, BarChart2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

const TRIGGER_LABELS = {
    all_comments: 'All comments',
    keyword: 'Keywords',
    mention_only: 'Mentions',
};

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
                        <div className="py-10 text-center">
                            <Zap className="mx-auto h-10 w-10 text-neutral-300 dark:text-neutral-700" />
                            <p className="mt-3 font-medium">No automations yet</p>
                            <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                                Create one to auto-reply to comments with a DM funnel.
                            </p>
                        </div>
                    </Card>
                ) : (
                    <Card padding={false}>
                        <div className="divide-y divide-neutral-200 dark:divide-neutral-800">
                            {automations.map((automation) => (
                                <div key={automation.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                                    <div className="min-w-0">
                                        <div className="flex items-center gap-2">
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
                                        <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">
                                            @{automation.account?.username ?? '—'} ·{' '}
                                            {automation.media_ids?.length
                                                ? `on ${automation.media_ids.length} specific post${automation.media_ids.length === 1 ? '' : 's'}`
                                                : 'all posts'}
                                            {' · '}
                                            {automation.trigger_type === 'keyword'
                                                ? `${TRIGGER_LABELS.keyword}: ${(automation.keywords ?? []).join(', ')} (${automation.match_mode})`
                                                : TRIGGER_LABELS[automation.trigger_type] ?? automation.trigger_type}
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
