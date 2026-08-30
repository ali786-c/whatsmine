import { Head, router, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import {
    Plus, QrCode, Trash2, RefreshCw, Wifi, WifiOff, Clock, Smartphone, Check, AlertTriangle,
} from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

function StatusBadge({ status }) {
    const { t } = useTranslation();
    const map = {
        active: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200 dark:bg-emerald-900/30 dark:text-emerald-300 dark:ring-emerald-800',
        qr_pending: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200 dark:bg-amber-900/30 dark:text-amber-300 dark:ring-amber-800',
        disconnected: 'bg-neutral-100 text-neutral-500 ring-1 ring-neutral-200 dark:bg-neutral-800 dark:text-neutral-400 dark:ring-neutral-700',
        error: 'bg-red-50 text-red-700 ring-1 ring-red-200 dark:bg-red-900/30 dark:text-red-300 dark:ring-red-800',
    };
    const dot = {
        active: 'bg-emerald-500',
        qr_pending: 'bg-amber-500',
        disconnected: 'bg-neutral-400',
        error: 'bg-red-500',
    };
    const labels = {
        active: t('common.active'),
        qr_pending: t('whatsappQr.pending_scan'),
        disconnected: t('whatsappQr.disconnected'),
        error: t('common.error'),
    };
    return (
        <span className={`inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ${map[status] ?? map.disconnected}`}>
            <span className={`h-1.5 w-1.5 rounded-full ${dot[status] ?? dot.disconnected}`} />
            {labels[status] ?? status}
        </span>
    );
}

function SessionRow({ session, onDelete }) {
    const { t } = useTranslation();
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const handleDelete = () => {
        setDeleting(true);
        router.delete(route('client.whatsapp-qr.destroy', { session: session.uuid }), {
            preserveScroll: true,
            onFinish: () => { setDeleting(false); setConfirmDelete(false); },
        });
    };

    return (
        <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/60 overflow-hidden hover:shadow-md transition-shadow">
            <div className="flex items-center justify-between gap-4 px-4 py-3.5">
                <div className="flex items-center gap-3 min-w-0">
                    <div className="rounded-lg bg-green-100 dark:bg-green-900/30 p-2 shrink-0">
                        <QrCode className="h-5 w-5 text-green-600 dark:text-green-400" />
                    </div>
                    <div className="min-w-0">
                        <div className="flex items-center gap-2 flex-wrap">
                            <Link
                                href={route('client.whatsapp-qr.show', { session: session.uuid })}
                                className="font-semibold text-sm text-neutral-900 dark:text-neutral-100 hover:text-brand-600 dark:hover:text-brand-400 transition truncate"
                            >
                                {session.label || session.session_id}
                            </Link>
                            <StatusBadge status={session.status} />
                        </div>
                        <div className="flex items-center gap-3 mt-1">
                            {session.phone_number && (
                                <span className="flex items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                                    <Smartphone className="h-3 w-3" /> {session.phone_number}
                                </span>
                            )}
                            {session.connected_at && (
                                <span className="flex items-center gap-1 text-xs text-neutral-400">
                                    <Clock className="h-3 w-3" /> {new Date(session.connected_at).toLocaleString()}
                                </span>
                            )}
                        </div>
                        {session.channel_account && (
                            <div className="flex items-center gap-1 mt-1 text-xs text-brand-600 dark:text-brand-400">
                                <Wifi className="h-3 w-3" /> {t('whatsappQr.linked_to_inbox')}
                            </div>
                        )}
                    </div>
                </div>

                <div className="flex items-center gap-2 shrink-0">
                    <Link
                        href={route('client.whatsapp-qr.show', { session: session.uuid })}
                        className="rounded-lg border border-neutral-200 dark:border-neutral-600 px-3 py-1.5 text-xs font-medium text-neutral-600 dark:text-neutral-300 hover:border-brand-300 hover:text-brand-600 dark:hover:text-brand-400 transition"
                    >
                        {t('whatsappQr.view_qr')}
                    </Link>
                    {confirmDelete ? (
                        <div className="flex items-center gap-1.5">
                            <button onClick={handleDelete} disabled={deleting}
                                className="rounded-lg bg-red-600 px-2.5 py-1.5 text-xs font-medium text-white hover:bg-red-700 disabled:opacity-60 transition">
                                {deleting ? t('inbox.removing') : t('inbox.confirm')}
                            </button>
                            <button onClick={() => setConfirmDelete(false)}
                                className="rounded-lg border border-neutral-200 dark:border-neutral-600 px-2.5 py-1.5 text-xs text-neutral-600 dark:text-neutral-400 hover:bg-neutral-50 dark:hover:bg-neutral-700 transition">
                                {t('common.cancel')}
                            </button>
                        </div>
                    ) : (
                        <button onClick={() => setConfirmDelete(true)}
                            className="rounded-lg border border-red-200 dark:border-red-800 p-1.5 text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 hover:text-red-600 transition"
                            title={t('whatsappQr.disconnect_delete')}>
                            <Trash2 className="h-3.5 w-3.5" />
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function WhatsappQRIndex({ sessions }) {
    const { t } = useTranslation();
    const [creating, setCreating] = useState(false);
    const [error, setError] = useState(null);

    const handleCreate = async () => {
        setCreating(true);
        setError(null);
        try {
            const res = await fetch(route('client.whatsapp-qr.store'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({}),
            });
            const json = await res.json();
            if (res.ok && json.session) {
                router.visit(route('client.whatsapp-qr.show', { session: json.session.uuid }));
            } else {
                setError(json.message ?? t('whatsappQr.create_failed'));
            }
        } catch (err) {
            setError(err?.message ?? t('whatsappQr.network_error'));
        } finally {
            setCreating(false);
        }
    };

    const activeCount = sessions.filter(s => s.status === 'active').length;

    return (
        <ClientLayout>
            <Head title={t('whatsappQr.title')} />

            <div className="max-w-4xl mx-auto px-4 sm:px-6 py-8">
                {/* Header */}
                <div className="flex items-center justify-between mb-6">
                    <div>
                        <h1 className="text-2xl font-bold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <QrCode className="h-6 w-6 text-green-600" />
                            {t('whatsappQr.title')}
                        </h1>
                        <p className="mt-1 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('whatsappQr.subtitle')}
                        </p>
                    </div>
                    <button
                        onClick={handleCreate}
                        disabled={creating}
                        className="flex items-center gap-2 rounded-lg bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white px-4 py-2.5 text-sm font-semibold shadow-sm transition"
                    >
                        {creating ? (
                            <RefreshCw className="h-4 w-4 animate-spin" />
                        ) : (
                            <Plus className="h-4 w-4" />
                        )}
                        {creating ? t('whatsappQr.creating') : t('whatsappQr.new_session')}
                    </button>
                </div>

                {/* Error */}
                {error && (
                    <div className="mb-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-600 dark:text-red-400 flex items-start gap-2">
                        <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                        <span className="flex-1">{error}</span>
                        <button onClick={() => setError(null)} className="text-red-400 hover:text-red-600">
                            <span className="sr-only">{t('common.close')}</span>
                            ×
                        </button>
                    </div>
                )}

                {/* Stats */}
                <div className="grid grid-cols-3 gap-4 mb-6">
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/60 px-4 py-3">
                        <div className="text-2xl font-bold text-neutral-900 dark:text-neutral-100">{sessions.length}</div>
                        <div className="text-xs text-neutral-500 dark:text-neutral-400">{t('whatsappQr.total_sessions')}</div>
                    </div>
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/60 px-4 py-3">
                        <div className="text-2xl font-bold text-emerald-600 dark:text-emerald-400">{activeCount}</div>
                        <div className="text-xs text-neutral-500 dark:text-neutral-400">{t('whatsappQr.active_connections')}</div>
                    </div>
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800/60 px-4 py-3">
                        <div className="text-2xl font-bold text-neutral-900 dark:text-neutral-100">{sessions.length - activeCount}</div>
                        <div className="text-xs text-neutral-500 dark:text-neutral-400">{t('whatsappQr.inactive')}</div>
                    </div>
                </div>

                {/* Sessions list */}
                {sessions.length > 0 ? (
                    <div className="space-y-3">
                        {sessions.map(session => (
                            <SessionRow key={session.id} session={session} />
                        ))}
                    </div>
                ) : (
                    <div className="rounded-2xl border-2 border-dashed border-neutral-200 dark:border-neutral-700 py-16 text-center">
                        <div className="mx-auto mb-4 rounded-2xl w-16 h-16 flex items-center justify-center bg-green-100 dark:bg-green-900/30">
                            <QrCode className="h-8 w-8 text-green-600 dark:text-green-400" />
                        </div>
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-1">
                            {t('whatsappQr.no_sessions')}
                        </h3>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400 mb-4 max-w-md mx-auto">
                            {t('whatsappQr.no_sessions_description')}
                        </p>
                        <button
                            onClick={handleCreate}
                            disabled={creating}
                            className="inline-flex items-center gap-2 rounded-lg bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white px-4 py-2.5 text-sm font-semibold shadow-sm transition"
                        >
                            <Plus className="h-4 w-4" />
                            {t('whatsappQr.create_first_session')}
                        </button>
                    </div>
                )}

                {/* Info card */}
                <div className="mt-8 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 p-4">
                    <h3 className="text-sm font-semibold text-neutral-700 dark:text-neutral-300 mb-2">
                        {t('whatsappQr.how_it_works_title')}
                    </h3>
                    <ol className="text-xs text-neutral-500 dark:text-neutral-400 space-y-1.5 list-decimal list-inside">
                        <li>{t('whatsappQr.step_1')}</li>
                        <li>{t('whatsappQr.step_2')}</li>
                        <li>{t('whatsappQr.step_3')}</li>
                        <li>{t('whatsappQr.step_4')}</li>
                    </ol>
                </div>
            </div>
        </ClientLayout>
    );
}
