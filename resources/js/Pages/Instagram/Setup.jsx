import { useEffect } from 'react';
import { Head, usePage, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Card from '@/Components/ui/Card';
import Button from '@/Components/ui/Button';
import { Instagram, Settings2, ShieldCheck, Link2 } from 'lucide-react';

const STATUS_STYLES = {
    active: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    token_expired: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    disconnected: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};

/**
 * Comment-automation manage page. Connections live in ONE place — the Inbox
 * Channels page (Inbox → Setup). Connecting there creates BOTH the Inbox
 * channel row (DMs) and this module's account row (comment automation), so
 * this page is read-only: it shows the mirrored accounts and links to the
 * single connect point.
 */
export default function InstagramSetup({ accounts = [], automationsCount = 0, igAuthUrl = null }) {
    const { props } = usePage();
    const flash = props.flash ?? {};
    const channelsUrl = route('client.inbox.setup');
    const active = accounts.filter((a) => a.status === 'active');

    // Business Login for Instagram redirect-back: when Instagram lands here
    // with ?code=, POST it to the connect endpoint and clean the URL so a
    // refresh cannot replay the code.
    useEffect(() => {
        const params = new window.URLSearchParams(window.location.search);
        const code = params.get('code');
        if (! code) return;
        params.delete('code');
        params.delete('state');
        window.history.replaceState({}, '', window.location.pathname + (params.toString() ? `?${params}` : ''));
        router.post(route('client.inbox.setup.instagram-login.connect'), { code }, {
            preserveScroll: true,
            onSuccess: () => router.reload({ only: [] }),
        });
    }, []);

    return (
        <ClientLayout>
            <Head title="Instagram Automation" />

            <div className="space-y-5">
                {flash.success && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
                        {flash.success}
                    </div>
                )}
                {flash.error && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                        {flash.error}
                    </div>
                )}

                <Card>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-gradient-to-tr from-purple-500 via-pink-500 to-orange-400 text-white">
                                <Instagram className="h-5 w-5" />
                            </div>
                            <div>
                                <h2 className="text-base font-semibold">Instagram Comment Automation</h2>
                                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                                    Connect a professional account to auto-reply to comments with a DM funnel.
                                </p>
                            </div>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {igAuthUrl ? (
                                <a href={igAuthUrl}>
                                    <Button>
                                        <Instagram className="h-4 w-4" /> Connect with Instagram
                                    </Button>
                                </a>
                            ) : (
                                <a href={channelsUrl}>
                                    <Button>
                                        <Settings2 className="h-4 w-4" /> Connect via Channels
                                    </Button>
                                </a>
                            )}
                        </div>
                    </div>
                    {igAuthUrl ? (
                        <div className="mt-3 rounded-lg border border-purple-200 bg-purple-50 px-4 py-3 text-sm text-purple-700 dark:border-purple-800 dark:bg-purple-900/30 dark:text-purple-300">
                            <strong>Connect with Instagram</strong> — log in with your Instagram credentials (no Facebook Page
                            needed). One click connects DMs in the Inbox <em>and</em> comment automation together.
                        </div>
                    ) : (
                        <div className="mt-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700 dark:border-blue-800 dark:bg-blue-900/30 dark:text-blue-300">
                            <strong>One connection powers everything.</strong> Instagram accounts are connected from the{' '}
                            <a href={channelsUrl} className="underline font-medium">Inbox → Setup (Channels)</a> page.
                            Connecting there enables Instagram DMs in the Inbox <em>and</em> comment automation together —
                            this page manages the automation side.
                        </div>
                    )}
                </Card>

                <Card padding={false}>
                    <div className="flex items-center justify-between border-b border-neutral-200 px-5 py-3 dark:border-neutral-800">
                        <h3 className="text-sm font-semibold">Connected accounts</h3>
                        <span className="text-xs text-neutral-500 dark:text-neutral-400">
                            {active.length} active · {automationsCount} automation{automationsCount === 1 ? '' : 's'}
                        </span>
                    </div>
                    {accounts.length === 0 ? (
                        <div className="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                            No Instagram accounts connected yet.{' '}
                            <a href={channelsUrl} className="font-medium text-brand-600 underline dark:text-brand-400">
                                Connect on the Channels page →
                            </a>
                        </div>
                    ) : (
                        <div className="divide-y divide-neutral-200 dark:divide-neutral-800">
                            {accounts.map((account) => (
                                <div key={account.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">@{account.username ?? account.ig_user_id}</span>
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[account.status] ?? STATUS_STYLES.disconnected}`}>
                                                {String(account.status).replaceAll('_', ' ')}
                                            </span>
                                            {(account.meta_json?.auth_type ?? null) === 'instagram_login' ? (
                                                <span className="rounded-full bg-purple-100 px-2 py-0.5 text-xs font-medium text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">
                                                    Instagram Login
                                                </span>
                                            ) : (
                                                <span className="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400">
                                                    Facebook Page
                                                </span>
                                            )}
                                        </div>
                                        <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                            IG ID {account.ig_user_id} · connected {new Date(account.created_at).toLocaleDateString()}
                                        </p>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <a
                                            href={route('client.instagram.automations.index')}
                                            className="inline-flex items-center gap-1 rounded-lg border border-neutral-200 px-3 py-1.5 text-sm text-neutral-700 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800"
                                        >
                                            <Link2 className="h-3.5 w-3.5" /> Automations
                                        </a>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                    {accounts.some((a) => a.status !== 'active') && (
                        <div className="border-t border-neutral-200 px-5 py-3 text-xs text-neutral-500 dark:border-neutral-800 dark:text-neutral-400">
                            Disconnected or expired accounts are managed on the{' '}
                            <a href={channelsUrl} className="underline">Channels page</a> — reconnect there to reactivate.
                        </div>
                    )}
                </Card>

                <Card>
                    <div className="flex items-start gap-3">
                        <ShieldCheck className="mt-0.5 h-5 w-5 text-green-600" />
                        <div className="text-sm text-neutral-600 dark:text-neutral-400">
                            <p className="font-medium text-neutral-900 dark:text-neutral-100">How the funnel works</p>
                            <p className="mt-1">
                                Someone comments → the bot sends <strong>one private-reply DM</strong> (lands in their Inbox if they
                                follow you, otherwise in their Request folder) → the DM asks them to follow and reply with your
                                keyword → their reply opens a 24-hour window and the bot delivers your link or file.
                            </p>
                            <p className="mt-1">
                                Meta limits: one private reply per comment, within 7 days; follow-ups only after they reply.
                                All limits are enforced automatically.
                            </p>
                        </div>
                    </div>
                </Card>
            </div>
        </ClientLayout>
    );
}
