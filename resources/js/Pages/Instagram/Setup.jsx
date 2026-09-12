import { Head, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Card from '@/Components/ui/Card';
import Button from '@/Components/ui/Button';
import { Instagram, Plus, ShieldCheck, AlertTriangle, Link2 } from 'lucide-react';
import { useState, useCallback, useEffect } from 'react';

const STATUS_STYLES = {
    active: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
    token_expired: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300',
    disconnected: 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400',
};

export default function InstagramSetup({ accounts = [], webhookUrl, metaAppId, metaConfigIdSocial }) {
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [connecting, setConnecting] = useState(false);
    const [oauthError, setOauthError] = useState(null);

    // Meta redirects back to this page with ?code=… (or ?error_description=…).
    // The state value is the OAuth CSRF guard: it must match the one we stored
    // before redirecting to Facebook, otherwise the code could be injected.
    useEffect(() => {
        const params = new window.URLSearchParams(window.location.search);
        const code = params.get('code');
        const error = params.get('error_description') || params.get('error');
        const state = params.get('state');

        if (!code && !error) {
            return;
        }

        window.history.replaceState({}, document.title, window.location.pathname);

        const expectedState = window.sessionStorage.getItem('instagram_oauth_state');
        window.sessionStorage.removeItem('instagram_oauth_state');

        if (error) {
            setOauthError(error);
            return;
        }

        if (!expectedState || expectedState !== state) {
            setOauthError('Invalid OAuth state — please start the connection again.');
            return;
        }

        setConnecting(true);
        router.post(route('client.instagram.connect'), { code }, {
            onFinish: () => setConnecting(false),
        });
    }, []);

    const launchConnect = useCallback(() => {
        if (!metaAppId || !metaConfigIdSocial) {
            alert('Meta App credentials or the social login config are not configured. Ask your administrator to set them in Admin → Integrations → Meta App.');
            return;
        }

        const redirectUri = route('client.instagram.setup');
        const state = typeof window.crypto?.randomUUID === 'function'
            ? window.crypto.randomUUID()
            : `${Date.now()}-${Math.random().toString(36).slice(2)}`;

        window.sessionStorage.setItem('instagram_oauth_state', state);

        const params = new window.URLSearchParams({
            client_id: metaAppId,
            redirect_uri: redirectUri,
            response_type: 'code',
            config_id: metaConfigIdSocial,
            state,
            override_default_response_type: 'true',
            extras: JSON.stringify({ feature_type: 'instagram_management' }),
        });

        window.location.assign(`https://www.facebook.com/v20.0/dialog/oauth?${params.toString()}`);
    }, [metaAppId, metaConfigIdSocial]);

    const disconnect = (id) => {
        if (confirm('Disconnect this Instagram account? Active automations will stop firing.')) {
            router.delete(route('client.instagram.disconnect', id));
        }
    };

    return (
        <ClientLayout>
            <Head title="Instagram Automation" />

            <div className="space-y-5">
                {flash.success && (
                    <div className="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700 dark:border-green-800 dark:bg-green-900/30 dark:text-green-300">
                        {flash.success}
                    </div>
                )}
                {(flash.error || oauthError) && (
                    <div className="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 dark:border-red-800 dark:bg-red-900/30 dark:text-red-300">
                        {flash.error || oauthError}
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
                        <Button onClick={launchConnect} disabled={connecting}>
                            <Plus className="h-4 w-4" /> Connect Instagram
                        </Button>
                    </div>
                </Card>

                <Card padding={false}>
                    <div className="border-b border-neutral-200 px-5 py-3 dark:border-neutral-800">
                        <h3 className="text-sm font-semibold">Connected accounts</h3>
                    </div>
                    {accounts.length === 0 ? (
                        <div className="px-5 py-10 text-center text-sm text-neutral-500 dark:text-neutral-400">
                            No Instagram accounts connected yet.
                        </div>
                    ) : (
                        <div className="divide-y divide-neutral-200 dark:divide-neutral-800">
                            {accounts.map((account) => (
                                <div key={account.id} className="flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="font-medium">@{account.username ?? account.ig_user_id}</span>
                                            <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLES[account.status] ?? STATUS_STYLES.disconnected}`}>
                                                {account.status.replaceAll('_', ' ')}
                                            </span>
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
                                        <Button variant="outline" size="sm" onClick={() => disconnect(account.id)}>
                                            Disconnect
                                        </Button>
                                    </div>
                                </div>
                            ))}
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

                {webhookUrl && (
                    <Card>
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="mt-0.5 h-5 w-5 text-amber-500" />
                            <div className="text-sm text-neutral-600 dark:text-neutral-400">
                                <p className="font-medium text-neutral-900 dark:text-neutral-100">Webhook endpoint</p>
                                <p className="mt-1 font-mono text-xs break-all">{webhookUrl}</p>
                                <p className="mt-1 text-xs">
                                    Registered automatically on connect (object <code>instagram</code>, fields
                                    {' '}<code>comments, messages, messaging_postbacks, message_reactions</code>).
                                </p>
                            </div>
                        </div>
                    </Card>
                )}
            </div>
        </ClientLayout>
    );
}
