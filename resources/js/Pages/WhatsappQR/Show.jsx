import { Head, router, Link } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import {
    ArrowLeft, QrCode, RefreshCw, Wifi, WifiOff, Clock, Smartphone, Trash2, Check, AlertTriangle, Loader2,
} from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';
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

export default function WhatsappQRShow({ session: initialSession }) {
    const { t } = useTranslation();
    const [session, setSession] = useState(initialSession);
    const [qrCode, setQrCode] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);
    const [deleting, setDeleting] = useState(false);

    const fetchQr = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const res = await fetch(route('client.whatsapp-qr.qr', { session: session.uuid }), {
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Accept': 'application/json',
                },
            });
            const json = await res.json();
            if (res.ok && json.qr_code) {
                setQrCode(json.qr_code);
                setSession(prev => ({ ...prev, status: json.status }));
            } else {
                setError(json.message ?? t('whatsappQr.qr_load_failed'));
            }
        } catch (err) {
            setError(err?.message ?? t('whatsappQr.network_error'));
        } finally {
            setLoading(false);
        }
    }, [session.uuid, t]);

    // Poll status periodically
    useEffect(() => {
        if (session.status === 'active') return;

        const interval = setInterval(async () => {
            try {
                const res = await fetch(route('client.whatsapp-qr.status', { session: session.uuid }), {
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });
                const json = await res.json();
                if (res.ok) {
                    setSession(prev => ({
                        ...prev,
                        status: json.status,
                        phone_number: json.phone_number,
                        connected_at: json.connected_at,
                        last_active_at: json.last_active_at,
                    }));
                    if (json.status === 'active') {
                        // Reload to get the full session data with channel account
                        router.reload({ only: ['session'] });
                    }
                }
            } catch {
                // Silently ignore polling errors
            }
        }, 3000);

        return () => clearInterval(interval);
    }, [session.uuid, session.status]);

    // Auto-fetch QR on mount if no QR code yet
    useEffect(() => {
        if (!qrCode && session.status !== 'active') {
            fetchQr();
        }
    }, []); // eslint-disable-line react-hooks/exhaustive-deps

    const handleDelete = () => {
        setDeleting(true);
        router.delete(route('client.whatsapp-qr.destroy', { session: session.uuid }), {
            preserveScroll: true,
            onFinish: () => setDeleting(false),
        });
    };

    const handleRefreshQr = () => {
        fetchQr();
    };

    return (
        <ClientLayout>
            <Head title={t('whatsappQr.session_detail')} />

            <div className="max-w-2xl mx-auto px-4 sm:px-6 py-8">
                {/* Back link */}
                <Link
                    href={route('client.whatsapp-qr.index')}
                    className="inline-flex items-center gap-1.5 text-sm text-neutral-500 dark:text-neutral-400 hover:text-brand-600 dark:hover:text-brand-400 transition mb-6"
                >
                    <ArrowLeft className="h-4 w-4" />
                    {t('whatsappQr.back_to_sessions')}
                </Link>

                {/* Session header */}
                <div className="rounded-2xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 shadow-sm overflow-hidden">
                    <div className="px-5 py-4 border-b border-neutral-100 dark:border-neutral-800">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center gap-3">
                                <div className="rounded-xl bg-green-100 dark:bg-green-900/30 p-2">
                                    <QrCode className="h-5 w-5 text-green-600 dark:text-green-400" />
                                </div>
                                <div>
                                    <h1 className="font-semibold text-neutral-900 dark:text-neutral-100">
                                        {session.label || session.session_id}
                                    </h1>
                                    <div className="flex items-center gap-2 mt-0.5">
                                        <StatusBadge status={session.status} />
                                        {session.phone_number && (
                                            <span className="flex items-center gap-1 text-xs text-neutral-500 dark:text-neutral-400">
                                                <Smartphone className="h-3 w-3" /> {session.phone_number}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </div>
                            <button
                                onClick={handleDelete}
                                disabled={deleting}
                                className="rounded-lg border border-red-200 dark:border-red-800 p-2 text-red-400 hover:bg-red-50 dark:hover:bg-red-950/40 hover:text-red-600 transition disabled:opacity-60"
                                title={t('whatsappQr.disconnect_delete')}
                            >
                                {deleting ? <Loader2 className="h-4 w-4 animate-spin" /> : <Trash2 className="h-4 w-4" />}
                            </button>
                        </div>
                    </div>

                    {/* QR Code area */}
                    <div className="p-8">
                        {session.status === 'active' ? (
                            <div className="text-center py-8">
                                <div className="mx-auto mb-4 rounded-full w-16 h-16 flex items-center justify-center bg-emerald-100 dark:bg-emerald-900/30">
                                    <Check className="h-8 w-8 text-emerald-600 dark:text-emerald-400" />
                                </div>
                                <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100 mb-1">
                                    {t('whatsappQr.connected')}
                                </h3>
                                <p className="text-sm text-neutral-500 dark:text-neutral-400 mb-4">
                                    {t('whatsappQr.connected_description')}
                                </p>
                                {session.phone_number && (
                                    <div className="inline-flex items-center gap-2 rounded-lg bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-800 px-4 py-2.5 text-sm text-emerald-700 dark:text-emerald-300">
                                        <Smartphone className="h-4 w-4" />
                                        {session.phone_number}
                                    </div>
                                )}
                                {session.connected_at && (
                                    <div className="flex items-center justify-center gap-1 mt-3 text-xs text-neutral-400">
                                        <Clock className="h-3 w-3" />
                                        {t('whatsappQr.connected_at')} {new Date(session.connected_at).toLocaleString()}
                                    </div>
                                )}
                                <div className="mt-6">
                                    <Link
                                        href={route('client.inbox.index')}
                                        className="inline-flex items-center gap-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white px-4 py-2.5 text-sm font-semibold shadow-sm transition"
                                    >
                                        <Wifi className="h-4 w-4" />
                                        {t('whatsappQr.go_to_inbox')}
                                    </Link>
                                </div>
                            </div>
                        ) : qrCode ? (
                            <div className="text-center">
                                <p className="text-sm text-neutral-600 dark:text-neutral-300 mb-4">
                                    {t('whatsappQr.scan_instruction')}
                                </p>
                                <div className="inline-block rounded-2xl bg-white p-4 shadow-lg border border-neutral-200 dark:border-neutral-700">
                                    <img
                                        src={qrCode}
                                        alt="WhatsApp QR Code"
                                        className="w-64 h-64"
                                    />
                                </div>
                                <p className="text-xs text-neutral-400 dark:text-neutral-500 mt-4">
                                    {t('whatsappQr.qr_refreshes_automatically')}
                                </p>
                                <button
                                    onClick={handleRefreshQr}
                                    disabled={loading}
                                    className="mt-3 inline-flex items-center gap-1.5 text-xs text-brand-600 dark:text-brand-400 hover:text-brand-700 disabled:opacity-60 font-medium transition"
                                >
                                    <RefreshCw className={`h-3.5 w-3.5 ${loading ? 'animate-spin' : ''}`} />
                                    {loading ? t('whatsappQr.refreshing') : t('whatsappQr.refresh_qr')}
                                </button>
                            </div>
                        ) : loading ? (
                            <div className="text-center py-16">
                                <Loader2 className="h-12 w-12 text-brand-500 animate-spin mx-auto mb-4" />
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                    {t('whatsappQr.generating_qr')}
                                </p>
                            </div>
                        ) : (
                            <div className="text-center py-16">
                                <QrCode className="h-12 w-12 text-neutral-300 dark:text-neutral-600 mx-auto mb-4" />
                                <p className="text-sm text-neutral-500 dark:text-neutral-400 mb-4">
                                    {t('whatsappQr.qr_not_available')}
                                </p>
                                <button
                                    onClick={handleRefreshQr}
                                    disabled={loading}
                                    className="inline-flex items-center gap-2 rounded-lg bg-green-600 hover:bg-green-700 disabled:opacity-60 text-white px-4 py-2.5 text-sm font-semibold shadow-sm transition"
                                >
                                    <RefreshCw className={`h-4 w-4 ${loading ? 'animate-spin' : ''}`} />
                                    {t('whatsappQr.generate_qr')}
                                </button>
                            </div>
                        )}

                        {/* Error */}
                        {error && (
                            <div className="mt-4 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 px-4 py-3 text-sm text-red-600 dark:text-red-400 flex items-start gap-2">
                                <AlertTriangle className="h-4 w-4 mt-0.5 shrink-0" />
                                <span className="flex-1">{error}</span>
                            </div>
                        )}
                    </div>

                    {/* Session info */}
                    <div className="px-5 py-3 bg-neutral-50 dark:bg-neutral-800 border-t border-neutral-100 dark:border-neutral-800">
                        <div className="grid grid-cols-2 gap-3 text-xs">
                            <div>
                                <span className="text-neutral-400 dark:text-neutral-500">{t('whatsappQr.session_id')}</span>
                                <code className="block font-mono text-neutral-600 dark:text-neutral-300 mt-0.5 truncate">{session.session_id}</code>
                            </div>
                            <div>
                                <span className="text-neutral-400 dark:text-neutral-500">{t('whatsappQr.created')}</span>
                                <span className="block text-neutral-600 dark:text-neutral-300 mt-0.5">{new Date(session.created_at).toLocaleString()}</span>
                            </div>
                        </div>
                    </div>
                </div>

                {/* Help */}
                <div className="mt-6 rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/60 p-4">
                    <h3 className="text-sm font-semibold text-neutral-700 dark:text-neutral-300 mb-2">
                        {t('whatsappQr.help_title')}
                    </h3>
                    <ol className="text-xs text-neutral-500 dark:text-neutral-400 space-y-1.5 list-decimal list-inside">
                        <li>{t('whatsappQr.help_step_1')}</li>
                        <li>{t('whatsappQr.help_step_2')}</li>
                        <li>{t('whatsappQr.help_step_3')}</li>
                    </ol>
                </div>
            </div>
        </ClientLayout>
    );
}
