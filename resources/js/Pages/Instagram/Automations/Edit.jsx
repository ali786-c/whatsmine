import { Head, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Card from '@/Components/ui/Card';
import Button from '@/Components/ui/Button';
import Input from '@/Components/ui/Input';
import Select from '@/Components/ui/Select';
import Toggle from '@/Components/ui/Toggle';
import { MessageCircle } from 'lucide-react';
import { useState } from 'react';
import axios from 'axios';

const EMPTY = {
    instagram_account_id: '',
    name: '',
    trigger_type: 'keyword',
    keywords: [],
    match_mode: 'contains',
    reply_message: 'Thanks for your comment! 🎉',
    follow_gate: false,
    follow_prompt_message: '',
    reply_keyword: 'DONE',
    delivery: { type: 'link', text: 'Here it is as promised:', url: '', filename: '' },
    media_filter: [],
    media_ids: [],
    is_active: true,
    priority: 100,
};

export default function InstagramAutomationEdit({ automation = null, accounts = [] }) {
    const { props } = usePage();
    const editing = Boolean(automation?.id);
    const [form, setForm] = useState(() => {
        if (!editing) return EMPTY;
        // Inertia serializes null JSON columns as null — normalize to the shapes
        // the editor mutates (arrays for chips, object for delivery).
        return {
            ...EMPTY,
            ...automation,
            keywords: automation.keywords ?? [],
            media_filter: automation.media_filter ?? [],
            delivery: { ...EMPTY.delivery, ...(automation.delivery ?? {}) },
        };
    });
    const [keywordInput, setKeywordInput] = useState('');
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [posts, setPosts] = useState([]);
    const [loadingPosts, setLoadingPosts] = useState(false);
    const [postsError, setPostsError] = useState(null);
    const [showPostPicker, setShowPostPicker] = useState(false);

    const loadPosts = () => {
        if (!form.instagram_account_id) {
            setPostsError('Select an Instagram account first.');
            return;
        }
        setLoadingPosts(true);
        setPostsError(null);
        setShowPostPicker(true);
        axios.get(route('client.instagram.automations.recent-posts'), { params: { account_id: form.instagram_account_id } })
            .then((res) => setPosts(res.data.posts ?? []))
            .catch((e) => setPostsError(e?.response?.data?.message ?? 'Could not load posts.'))
            .finally(() => setLoadingPosts(false));
    };

    const togglePost = (id) => {
        set('media_ids', form.media_ids.includes(id)
            ? form.media_ids.filter((x) => x !== id)
            : [...form.media_ids, id]);
    };

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));
    const setDelivery = (key, value) => setForm((f) => ({ ...f, delivery: { ...f.delivery, [key]: value } }));

    const addKeyword = () => {
        const k = keywordInput.trim();
        if (k && !form.keywords.includes(k) && form.keywords.length < 20) {
            set('keywords', [...form.keywords, k]);
        }
        setKeywordInput('');
    };

    const preview = [
        form.reply_message,
        form.follow_gate
            ? `\n\n${form.follow_prompt_message?.trim() || `Make sure you're following us, then reply with ${(form.reply_keyword || 'DONE')} and I'll send it over!`}`
            : (form.delivery.type === 'link' && form.delivery.url ? `\n\n${form.delivery.text || ''}\n${form.delivery.url}` : null),
    ].filter(Boolean).join('');

    const submit = (e) => {
        e.preventDefault();
        setSaving(true);
        setErrors({});

        const payload = {
            ...form,
            delivery: form.delivery.url || form.delivery.text ? form.delivery : null,
            _method: editing ? 'PUT' : 'POST',
        };

        const url = editing
            ? route('client.instagram.automations.update', automation.id)
            : route('client.instagram.automations.store');

        router.post(url, payload, {
            onError: (err) => { setErrors(err); setSaving(false); },
            onSuccess: () => setSaving(false),
        });
    };

    return (
        <ClientLayout>
            <Head title={editing ? 'Edit automation' : 'New automation'} />

            <form onSubmit={submit} className="space-y-5">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold">{editing ? 'Edit automation' : 'New automation'}</h1>
                    <div className="flex items-center gap-2">
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                        <Button type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save automation'}</Button>
                    </div>
                </div>

                <Card>
                    <p className="mb-4 text-sm font-semibold">1 · Trigger</p>
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">Instagram account</label>
                            <Select
                                value={form.instagram_account_id}
                                onChange={(e) => set('instagram_account_id', e.target.value)}
                                options={accounts.map((a) => ({ value: a.id, label: `@${a.username ?? a.ig_user_id}` }))}
                                placeholder="Select account…"
                            />
                            {errors.instagram_account_id && <p className="mt-1 text-xs text-red-500">{errors.instagram_account_id}</p>}
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">Name</label>
                            <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Reel drop — send link" />
                            {errors.name && <p className="mt-1 text-xs text-red-500">{errors.name}</p>}
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">Trigger on</label>
                            <Select
                                value={form.trigger_type}
                                onChange={(e) => set('trigger_type', e.target.value)}
                                options={[
                                    { value: 'keyword', label: 'Comments containing keywords' },
                                    { value: 'all_comments', label: 'All comments' },
                                    { value: 'mention_only', label: 'Mentions only' },
                                ]}
                            />
                        </div>
                        {form.trigger_type === 'keyword' && (
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">Keywords</label>
                                <div className="flex gap-2">
                                    <Input
                                        value={keywordInput}
                                        onChange={(e) => setKeywordInput(e.target.value)}
                                        onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); addKeyword(); } }}
                                        placeholder="price, cost…"
                                    />
                                    <Button type="button" variant="secondary" onClick={addKeyword}>Add</Button>
                                </div>
                                <div className="mt-2 flex flex-wrap gap-1.5">
                                    {form.keywords.map((k) => (
                                        <button
                                            key={k}
                                            type="button"
                                            onClick={() => set('keywords', form.keywords.filter((x) => x !== k))}
                                            className="rounded-full bg-neutral-100 px-2.5 py-0.5 text-xs dark:bg-neutral-800"
                                        >
                                            {k} ✕
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">Match mode</label>
                            <Select
                                value={form.match_mode}
                                onChange={(e) => set('match_mode', e.target.value)}
                                options={[
                                    { value: 'contains', label: 'Contains keyword' },
                                    { value: 'exact', label: 'Exact match' },
                                    { value: 'starts_with', label: 'Starts with' },
                                    { value: 'regex', label: 'Regex (advanced)' },
                                ]}
                            />
                        </div>
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">Only on media type (optional)</label>
                            <div className="flex gap-3 text-sm">
                                {['POST', 'REEL', 'STORY', 'AD'].map((t) => (
                                    <label key={t} className="flex items-center gap-1.5">
                                        <input
                                            type="checkbox"
                                            checked={form.media_filter.includes(t)}
                                            onChange={(e) => set('media_filter', e.target.checked ? [...form.media_filter, t] : form.media_filter.filter((x) => x !== t))}
                                        />
                                        {t.toLowerCase()}
                                    </label>
                                ))}
                            </div>
                        </div>
                        <div className="md:col-span-2">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <p className="text-sm font-medium">Limit to specific posts (optional)</p>
                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                        Empty = runs on ALL posts. Post-specific automations always override general ones.
                                    </p>
                                </div>
                                <Button type="button" variant="secondary" size="sm" onClick={loadPosts} disabled={loadingPosts}>
                                    {loadingPosts ? 'Loading…' : (showPostPicker ? 'Refresh posts' : 'Pick posts')}
                                </Button>
                            </div>
                            {postsError && <p className="mt-2 text-xs text-red-500">{postsError}</p>}
                            {!showPostPicker && form.media_ids.length > 0 && (
                                <p className="mt-1 text-xs text-neutral-600 dark:text-neutral-400">{form.media_ids.length} post(s) selected — click “Refresh posts” to change.</p>
                            )}
                            {showPostPicker && (
                                <div className="mt-3 grid grid-cols-3 gap-2 sm:grid-cols-4 md:grid-cols-6">
                                    {posts.map((p) => {
                                        const selected = form.media_ids.includes(p.id);
                                        return (
                                            <button
                                                key={p.id}
                                                type="button"
                                                onClick={() => togglePost(p.id)}
                                                className={`relative overflow-hidden rounded-lg border-2 transition ${selected ? 'border-brand-500 ring-2 ring-brand-500/30' : 'border-transparent hover:border-neutral-300'}`}
                                            >
                                                {p.thumbnail ? (
                                                    <img src={p.thumbnail} alt="" className="h-20 w-full object-cover" />
                                                ) : (
                                                    <div className="flex h-20 items-center justify-center bg-neutral-100 text-[10px] text-neutral-400 dark:bg-neutral-800">no image</div>
                                                )}
                                                <span className="absolute left-1 top-1 rounded bg-black/60 px-1 text-[10px] font-medium text-white">{p.type || 'POST'}</span>
                                                {selected && (
                                                    <span className="absolute right-1 top-1 flex h-4 w-4 items-center justify-center rounded-full bg-brand-500 text-[10px] font-bold text-white">✓</span>
                                                )}
                                                <p className="truncate px-1 py-0.5 text-[10px] text-neutral-500 dark:text-neutral-400">{p.caption || p.id}</p>
                                            </button>
                                        );
                                    })}
                                    {posts.length === 0 && !loadingPosts && (
                                        <p className="col-span-full py-2 text-xs text-neutral-500 dark:text-neutral-400">No posts returned — the account may have no posts yet.</p>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </Card>

                <Card>
                    <p className="mb-4 text-sm font-semibold">2 · Private reply DM</p>
                    <label className="mb-1.5 block text-sm font-medium">Message</label>
                    <textarea
                        value={form.reply_message}
                        onChange={(e) => set('reply_message', e.target.value)}
                        rows={3}
                        maxLength={1000}
                        className="w-full rounded-soft border border-soft border-neutral-200 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"
                        placeholder="Thanks for your comment!"
                    />
                    <p className="mt-1 text-xs text-neutral-500">Tokens: {'{username}'} — Meta allows only ONE private reply per comment, so everything below is included in this single message.</p>
                    {errors.reply_message && <p className="mt-1 text-xs text-red-500">{errors.reply_message}</p>}

                    <div className="mt-4 flex items-center justify-between rounded-lg border border-neutral-200 px-4 py-3 dark:border-neutral-800">
                        <div>
                            <p className="text-sm font-medium">Follow gate</p>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                Ask them to follow you and reply with a keyword — the delivery is only sent after their reply opens a 24-hour window.
                            </p>
                        </div>
                        <Toggle checked={form.follow_gate} onChange={(v) => set('follow_gate', v)} />
                    </div>

                    {form.follow_gate && (
                        <div className="mt-3 grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">Follow prompt (added to the DM)</label>
                                <Input
                                    value={form.follow_prompt_message}
                                    onChange={(e) => set('follow_prompt_message', e.target.value)}
                                    placeholder={`Make sure you're following us, then reply with ${form.reply_keyword || 'DONE'}…`}
                                />
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">Keyword they must reply</label>
                                <Input value={form.reply_keyword} onChange={(e) => set('reply_keyword', e.target.value)} placeholder="DONE" />
                            </div>
                        </div>
                    )}
                </Card>

                <Card>
                    <p className="mb-4 text-sm font-semibold">3 · Delivery {form.follow_gate && '(sent after their reply)'}</p>
                    <div className="grid gap-4 md:grid-cols-2">
                        <div>
                            <label className="mb-1.5 block text-sm font-medium">Type</label>
                            <Select
                                value={form.delivery.type}
                                onChange={(e) => setDelivery('type', e.target.value)}
                                options={[
                                    { value: 'link', label: 'Link' },
                                    { value: 'text', label: 'Text' },
                                    { value: 'file', label: 'File (public URL)' },
                                ]}
                            />
                        </div>
                        {(form.delivery.type === 'link' || form.delivery.type === 'file') && (
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">URL</label>
                                <Input value={form.delivery.url} onChange={(e) => setDelivery('url', e.target.value)} placeholder="https://…" />
                                {errors['delivery.url'] && <p className="mt-1 text-xs text-red-500">{errors['delivery.url']}</p>}
                            </div>
                        )}
                        {form.delivery.type !== 'text' && (
                            <div className="md:col-span-2">
                                <label className="mb-1.5 block text-sm font-medium">Accompanying text (optional)</label>
                                <Input value={form.delivery.text} onChange={(e) => setDelivery('text', e.target.value)} />
                            </div>
                        )}
                        {form.delivery.type === 'text' && (
                            <div className="md:col-span-2">
                                <label className="mb-1.5 block text-sm font-medium">Text</label>
                                <Input value={form.delivery.text} onChange={(e) => setDelivery('text', e.target.value)} />
                            </div>
                        )}
                    </div>

                    <div className="mt-5 rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800/60">
                        <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                            <MessageCircle className="h-3.5 w-3.5" /> DM preview
                        </p>
                        <div className="max-w-md whitespace-pre-wrap rounded-2xl rounded-bl-sm bg-white px-4 py-2.5 text-sm shadow dark:bg-neutral-900">
                            {preview || <span className="text-neutral-400">Start typing to see the DM…</span>}
                        </div>
                    </div>
                </Card>
            </form>
        </ClientLayout>
    );
}
