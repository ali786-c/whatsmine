import { Head, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Card from '@/Components/ui/Card';
import Select from '@/Components/ui/Select';
import { BarChart2, ChevronDown } from 'lucide-react';
import { useState } from 'react';

const ACTION_STYLES = {
    dm_sent: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    delivered: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    matched: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    awaiting_follow: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    dm_failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    delivery_failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    expired: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    closed: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};

export default function InstagramLogsIndex({ logs = [], filters = {} }) {
    const { props } = usePage();
    const [expanded, setExpanded] = useState(null);

    const setFilter = (action) => router.get(route('client.instagram.logs.index'), action ? { action } : {}, { preserveState: true });

    return (
        <ClientLayout>
            <Head title="Instagram Funnel Logs" />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">Funnel logs</h1>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">Every comment event and send attempt, newest first.</p>
                    </div>
                    <div className="w-64">
                        <Select
                            value={filters.action ?? ''}
                            onChange={(e) => setFilter(e.target.value)}
                            options={[
                                { value: '', label: 'All actions' },
                                ...Object.keys(ACTION_STYLES).concat(['received', 'no_match', 'nudged', 'skipped', 'rate_limited']).map((a) => ({ value: a, label: a.replaceAll('_', ' ') })),
                            ].filter((o, i, arr) => arr.findIndex((x) => x.value === o.value) === i)}
                        />
                    </div>
                </div>

                <Card padding={false}>
                    {logs.length === 0 ? (
                        <div className="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                            <BarChart2 className="mx-auto h-10 w-10 text-neutral-300 dark:text-neutral-700" />
                            <p className="mt-3">No events logged yet.</p>
                        </div>
                    ) : (
                        <div className="divide-y divide-neutral-200 dark:divide-neutral-800">
                            {logs.map((log) => (
                                <div key={log.id}>
                                    <button
                                        type="button"
                                        onClick={() => setExpanded(expanded === log.id ? null : log.id)}
                                        className="flex w-full items-center justify-between gap-3 px-5 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800/50"
                                    >
                                        <div className="flex min-w-0 items-center gap-3">
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${ACTION_STYLES[log.action] ?? 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400'}`}>
                                                {log.action.replaceAll('_', ' ')}
                                            </span>
                                            <span className="truncate text-sm text-neutral-600 dark:text-neutral-400">
                                                {log.automation_name ?? '—'} · {log.comment_id ?? 'no comment'}
                                            </span>
                                            {log.error && <span className="truncate text-xs text-red-500">{log.error}</span>}
                                        </div>
                                        <div className="flex shrink-0 items-center gap-2 text-xs text-neutral-400">
                                            {new Date(log.created_at).toLocaleString()}
                                            <ChevronDown className={`h-4 w-4 transition ${expanded === log.id ? 'rotate-180' : ''}`} />
                                        </div>
                                    </button>
                                    {expanded === log.id && (
                                        <pre className="max-h-72 overflow-auto border-t border-neutral-100 bg-neutral-50 px-5 py-3 text-xs dark:border-neutral-800 dark:bg-neutral-900">
{JSON.stringify({ request: log.request_json, response: log.response_json }, null, 2)}
                                        </pre>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </Card>
            </div>
        </ClientLayout>
    );
}
