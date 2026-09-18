import { Head, Link, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { useTranslation } from 'react-i18next';
import { Plus, Workflow, Trash2, Pencil, Play, Pause } from 'lucide-react';
import Button from '@/Components/ui/Button';

export default function IgFlowsIndex({ flows, accountsCount = null }) {
    const { t } = useTranslation();

    const toggle = (flow) => {
        router.patch(route('client.instagram.flows.toggle', flow.id), {}, { preserveScroll: true });
    };

    const destroy = (flow) => {
        if (confirm(t('instagram_flows.delete_confirm'))) {
            router.delete(route('client.instagram.flows.destroy', flow.id), { preserveScroll: true });
        }
    };

    return (
        <ClientLayout>
            <Head title="DM Flows" />

            <div className="mx-auto max-w-5xl p-6">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="flex items-center gap-2 text-xl font-bold text-neutral-900 dark:text-white">
                            <Workflow className="h-5 w-5 text-brand-600" />
                            {t('instagram_flows.title')}
                        </h1>
                        <p className="mt-1 text-sm text-neutral-500">{t('instagram_flows.subtitle')}</p>
                    </div>                        <Link href={route('client.instagram.flows.create')}>
                            <Button size="sm">
                                <Plus className="h-4 w-4" />
                                {t('instagram_flows.new')}
                            </Button>
                        </Link>
                </div>

                {accountsCount === 0 && (
                    <div className="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                        <strong>Connect Instagram first.</strong> A flow runs on a connected account — connect yours on the{' '}
                        <a href={route('client.instagram.setup')} className="font-medium underline">Setup page</a>, then come back.
                    </div>
                )}

                <div className="mb-5 rounded-xl border border-neutral-200 bg-neutral-50 px-4 py-3 text-sm text-neutral-600 dark:border-neutral-800 dark:bg-neutral-900/60 dark:text-neutral-300">
                    <p className="font-medium text-neutral-800 dark:text-neutral-100">What is a DM flow?</p>
                    <p className="mt-1 leading-relaxed">
                        A flow is a mini chatbot: someone comments → the first DM goes out → the flow waits for their reply and
                        continues — like asking “Did you follow us?” with Yes / Not-yet buttons and delivering the file only on Yes.
                        Build it here, then pick <strong>“A DM flow”</strong> as the delivery when creating a comment automation.
                    </p>
                </div>

                {flows.length === 0 ? (
                    <div className="rounded-2xl border border-dashed border-neutral-300 dark:border-neutral-700 p-12 text-center">
                        <Workflow className="mx-auto h-10 w-10 text-neutral-300" />
                        <p className="mt-3 text-sm font-medium text-neutral-500">{t('instagram_flows.empty')}</p>
                        <p className="mt-1 text-xs text-neutral-400">{t('instagram_flows.empty_hint')}</p>
                        <Link href={route('client.instagram.flows.create')} className="mt-4 inline-block">
                            <Button size="sm" variant="outline">
                                <Plus className="h-4 w-4" />
                                {t('instagram_flows.new')}
                            </Button>
                        </Link>
                    </div>
                ) : (
                    <div className="space-y-3">
                        {flows.map((flow) => (
                            <div key={flow.id}
                                className="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4">
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <h3 className="truncate text-sm font-bold text-neutral-900 dark:text-white">{flow.name}</h3>
                                        <span className={`rounded-full px-2 py-0.5 text-[10px] font-bold uppercase ${
                                            flow.status === 'active'
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800'
                                        }`}>
                                            {flow.status}
                                        </span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-neutral-500">
                                        {t('instagram_flows.nodes_count', { n: flow.node_count })}
                                        {' · '}
                                        {t('instagram_flows.participants_count', { n: flow.participants_count })}
                                        {flow.account?.username ? ` · @${flow.account.username}` : ''}
                                    </p>
                                </div>

                                <div className="flex items-center gap-1.5">
                                    <Button size="sm" variant={flow.status === 'active' ? 'outline' : 'default'} onClick={() => toggle(flow)}>
                                        {flow.status === 'active' ? <><Pause className="h-3.5 w-3.5" />{t('instagram_flows.pause')}</> : <><Play className="h-3.5 w-3.5" />{t('instagram_flows.activate')}</>}
                                    </Button>
                                    <Link href={route('client.instagram.flows.edit', flow.id)}>
                                        <Button size="sm" variant="outline"><Pencil className="h-3.5 w-3.5" /></Button>
                                    </Link>
                                    <Button size="sm" variant="outline" className="text-red-500" onClick={() => destroy(flow)}>
                                        <Trash2 className="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}
