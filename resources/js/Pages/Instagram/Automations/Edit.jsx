import { Head, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import Card from '@/Components/ui/Card';
import Button from '@/Components/ui/Button';
import Input from '@/Components/ui/Input';
import Select from '@/Components/ui/Select';
import Toggle from '@/Components/ui/Toggle';
import Checkbox from '@/Components/ui/Checkbox';
import { MessageCircle, ChevronLeft, ChevronRight, Settings2, AlertCircle } from 'lucide-react';
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
    delivery: { type: 'link', text: 'Here it is as promised:', url: '', filename: '', flow_id: null },
    media_filter: [],
    media_ids: [],
    is_active: true,
    priority: 100,
};

const STEPS = [
    { title: 'When it runs', short: 'Trigger' },
    { title: 'What you send', short: 'Message' },
    { title: 'How they get it', short: 'Delivery' },
];

const TRIGGER_CHOICES = [
    { value: 'all_comments', label: 'Every comment', hint: 'Anyone who comments gets the DM — best for giveaways and drops.' },
    { value: 'keyword', label: 'Comments with keywords', hint: 'Only comments containing one of your words, e.g. "price" or "link".' },
    { value: 'mention_only', label: 'Comments mentioning a friend', hint: 'Only when the commenter tags someone (@friend) — grows your reach.' },
];

const DELIVERY_CHOICES = [
    { value: 'link', label: 'A link', hint: 'Send them a URL — product page, Google Drive, anything public.' },
    { value: 'file', label: 'A file', hint: 'Send a downloadable file from a public URL (PDF, ZIP…).' },
    { value: 'text', label: 'Just text', hint: 'Send a plain message — coupon code, instructions, anything.' },
    { value: 'flow', label: 'A DM flow', hint: 'Run one of your visual DM flows (Instagram → DM Flows) — multi-step chats with buttons and questions. Needs the follow ask on step 2.' },
];

function StepControls({ step, errors, saving, currentError, onBack, onNext }) {
    // The frontend per-step error takes priority; otherwise surface the first
    // backend error (when the server rejected a submit). The Next button stays
    // clickable so the user always SEES why they cannot advance — a disabled
    // button with no explanation is exactly the bug this fixes.
    const shownError = currentError ?? Object.values(errors ?? {})[0] ?? null;
    return (
        <div className="flex flex-wrap items-center justify-between gap-3">
            {shownError ? (
                <p className="flex items-start gap-1.5 text-sm text-red-500" role="alert">
                    <AlertCircle className="mt-0.5 h-4 w-4 shrink-0" /> {shownError}
                </p>
            ) : (
                <p className="text-sm text-neutral-500 dark:text-neutral-400">
                    Step {step + 1} of {STEPS.length} — {STEPS[step].title}
                </p>
            )}
            <div className="flex items-center gap-2">
                {step > 0 && (
                    <Button type="button" variant="outline" onClick={onBack}>
                        <ChevronLeft className="h-4 w-4" /> Back
                    </Button>
                )}
                {step < STEPS.length - 1 ? (
                    <Button type="button" onClick={onNext}>
                        Next <ChevronRight className="h-4 w-4" />
                    </Button>
                ) : (
                    <Button type="submit" disabled={saving || Boolean(currentError)}>{saving ? 'Saving…' : 'Save automation'}</Button>
                )}
            </div>
        </div>
    );
}

export default function InstagramAutomationEdit({ automation = null, accounts = [], flows = [], accountsCount = null }) {
    const editing = Boolean(automation?.id);
    const [form, setForm] = useState(() => {
        // `automation` is either an existing record (editing), a template preset
        // from ?template= (no id — still a create), or null (blank create).
        if (!automation) return EMPTY;
        // Inertia serializes null JSON columns as null — normalize to the shapes
        // the editor mutates (arrays for chips, object for delivery).
        return {
            ...EMPTY,
            ...automation,
            keywords: automation.keywords ?? [],
            media_filter: automation.media_filter ?? [],
            delivery: { ...EMPTY.delivery, ...(automation.delivery ?? {}), flow_id: automation.delivery?.flow_id ?? null },
        };
    });
    const [keywordInput, setKeywordInput] = useState('');
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    const [step, setStep] = useState(0);
    // True after the user clicks Next with an invalid step — gates the
    // per-field red hints so a fresh form doesn't shout errors immediately.
    const [attempted, setAttempted] = useState(false);
    const [posts, setPosts] = useState([]);
    const [loadingPosts, setLoadingPosts] = useState(false);
    const [loadingMore, setLoadingMore] = useState(false);
    const [postsError, setPostsError] = useState(null);
    const [showPostPicker, setShowPostPicker] = useState(false);
    const [nextCursor, setNextCursor] = useState(null);

    const set = (key, value) => setForm((f) => ({ ...f, [key]: value }));

    // Account switched → the old feed/pagination state is stale; reset the picker.
    const changeAccount = (value) => {
        set('instagram_account_id', value);
        setPosts([]);
        setNextCursor(null);
        setShowPostPicker(false);
        setPostsError(null);
    };
    const setDelivery = (key, value) => setForm((f) => ({ ...f, delivery: { ...f.delivery, [key]: value } }));

    // ---- per-step validation (mirrors backend rules) ----
    const stepValid = (s, f) => {
        if (s === 0) {
            if (!f.instagram_account_id) return 'Choose which Instagram account to run on.';
            if (!f.name.trim()) return 'Give this automation a name.';
            if (f.trigger_type === 'keyword' && f.keywords.length === 0) return 'Add at least one keyword.';
            return null;
        }
        if (s === 1) {
            if (!f.reply_message.trim()) return 'Write the DM message.';
            if ((f.reply_message ?? '').length > 1000) return 'The DM message is too long (max 1000 characters).';
            if (f.follow_gate) {
                if ((f.follow_prompt_message ?? '').length > 500) return 'The follow prompt is too long (max 500 characters).';
                if ((f.reply_keyword ?? '').trim().length > 64) return 'The keyword is too long (max 64 characters).';
            }
            return null;
        }
        if (s === 2) {
            if (f.delivery.type === 'flow' && !f.follow_gate) return 'A DM flow needs the follow ask turned on (step 2) — the flow starts only after they reply.';
            if (f.delivery.type === 'flow' && !f.delivery.flow_id) return 'Pick which DM flow should run.';
            if ((f.delivery.url ?? '').length > 2048) return 'The URL is too long (max 2048 characters).';
            if ((f.delivery.text ?? '').length > 900) return 'The delivery text is too long (max 900 characters).';
            if ((f.delivery.filename ?? '').length > 120) return 'The file name is too long (max 120 characters).';
            return null;
        }
        return null;
    };

    const currentError = stepValid(step, form);

    const goto = (next) => {
        setAttempted(true);
        if (currentError) return;
        setStep(next);
        setAttempted(false);
    };

    const loadPosts = (cursor = null, append = false) => {
        if (!form.instagram_account_id) {
            setPostsError('Select an Instagram account first.');
            return;
        }
        if (append) {
            setLoadingMore(true);
        } else {
            setLoadingPosts(true);
        }
        setPostsError(null);
        setShowPostPicker(true);
        const params = { account_id: form.instagram_account_id };
        if (cursor) params.cursor = cursor;
        axios.get(route('client.instagram.automations.recent-posts'), { params })
            .then((res) => {
                const newPosts = res.data.posts ?? [];
                setPosts((prev) => (append
                    ? [...prev, ...newPosts.filter((p) => !prev.some((q) => q.id === p.id))]
                    : newPosts));
                setNextCursor(res.data.next_cursor ?? null);
            })
            .catch((e) => setPostsError(e?.response?.data?.message ?? 'Could not load posts.'))
            .finally(() => { setLoadingPosts(false); setLoadingMore(false); });
    };

    const togglePost = (id) => {
        set('media_ids', form.media_ids.includes(id)
            ? form.media_ids.filter((x) => x !== id)
            : [...form.media_ids, id]);
    };

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

    const triggerLabel = form.trigger_type === 'keyword' && form.keywords.length > 0
        ? `"${form.keywords.join('", "')}" comments`
        : TRIGGER_CHOICES.find((t) => t.value === form.trigger_type)?.label?.toLowerCase() ?? 'the trigger';

    const deliveryLabel = form.delivery.type === 'flow'
        ? `After they reply "${(form.reply_keyword || 'DONE').trim()}", your DM flow takes over the conversation`
        : form.follow_gate
            ? `After they reply "${(form.reply_keyword || 'DONE').trim()}" they get ${DELIVERY_CHOICES.find((d) => d.value === form.delivery.type)?.label.toLowerCase() ?? 'the delivery'}`
            : `${DELIVERY_CHOICES.find((d) => d.value === form.delivery.type)?.label ?? 'Delivery'} goes out inside the first DM`;

    return (
        <ClientLayout>
            <Head title={editing ? 'Edit automation' : 'New automation'} />

            <form onSubmit={submit} className="space-y-5">
                {/* sticky header with the 3-step progress */}
                <div className="sticky top-0 z-10 -mx-5 border-b border-neutral-200 bg-white/90 px-5 py-3 backdrop-blur dark:border-neutral-800 dark:bg-neutral-950/90">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h1 className="text-lg font-semibold">{editing ? 'Edit automation' : 'New automation'}</h1>
                        <Button type="button" variant="outline" onClick={() => window.history.back()}>Cancel</Button>
                    </div>
                    <div className="mt-3 flex items-center gap-2">
                        {STEPS.map((s, i) => (
                            <div key={s.short} className="flex flex-1 flex-col gap-1">
                                <div className={`h-1.5 rounded-full transition-colors ${i <= step ? 'bg-brand-500' : 'bg-neutral-200 dark:bg-neutral-800'}`} />
                                <span className={`text-xs font-medium ${i === step ? 'text-brand-600 dark:text-brand-400' : 'text-neutral-400'}`}>
                                    {i + 1} · {s.short}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>

                {/* STEP 1 — trigger */}
                {step === 0 && (
                    <Card>
                        <p className="mb-1 text-sm font-semibold">When should this run?</p>
                        <p className="mb-4 text-xs text-neutral-500 dark:text-neutral-400">
                            Pick the account and decide which comments trigger the DM.
                        </p>
                        <div className="grid gap-4 md:grid-cols-2">
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">Instagram account</label>                    <Select value={form.instagram_account_id} onChange={(e) => changeAccount(e.target.value)}
                                    options={accounts.map((a) => ({ value: a.id, label: `@${a.username ?? a.ig_user_id}` }))}
                                    placeholder="Select account…"
                                />
                                {errors.instagram_account_id && <p className="mt-1 text-xs text-red-500">{errors.instagram_account_id}</p>}
                                {attempted && !form.instagram_account_id && !errors.instagram_account_id && (
                                    <p className="mt-1 text-xs text-red-500">Select your Instagram account first.</p>
                                )}
                            </div>
                            <div>
                                <label className="mb-1.5 block text-sm font-medium">Name (just for you)</label>
                                <Input value={form.name} onChange={(e) => set('name', e.target.value)} placeholder="Reel drop — send link" />
                                {errors.name && <p className="mt-1 text-xs text-red-500">{errors.name}</p>}
                                {attempted && !form.name.trim() && !errors.name && (
                                    <p className="mt-1 text-xs text-red-500">Give this automation a name.</p>
                                )}
                            </div>
                        </div>

                        <p className="mb-2 mt-5 text-sm font-medium">Which comments?</p>
                        <div className="grid gap-3 md:grid-cols-3">
                            {TRIGGER_CHOICES.map((choice) => (
                                <button
                                    key={choice.value}
                                    type="button"
                                    onClick={() => set('trigger_type', choice.value)}
                                    className={`rounded-xl border-2 p-4 text-left transition ${
                                        form.trigger_type === choice.value
                                            ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/30 dark:bg-brand-950/40'
                                            : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-800 dark:hover:border-neutral-700'
                                    }`}
                                >
                                    <p className="text-sm font-semibold">{choice.label}</p>
                                    <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{choice.hint}</p>
                                </button>
                            ))}
                        </div>

                        {form.trigger_type === 'keyword' && (
                            <div className="mt-4">
                                <label className="mb-1.5 block text-sm font-medium">Your keywords</label>
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
                                {attempted && form.keywords.length === 0 && (
                                    <p className="mt-2 text-xs text-red-500">Add at least one keyword — e.g. “price”.</p>
                                )}
                            </div>
                        )}                        {/* Advanced options are always visible — hiding them behind
                            a toggle made match-mode and post-limiting
                            undiscoverable. The keyword matcher only applies when
                            the trigger is "comments with keywords". */}
                        <p className="mt-5 flex items-center gap-1.5 border-t border-neutral-100 pt-4 text-sm font-semibold text-neutral-600 dark:border-neutral-800 dark:text-neutral-300">
                            <Settings2 className="h-4 w-4" /> Advanced options
                        </p>

                        <div className="mt-3 space-y-4 rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800/60">
                                <div className="grid gap-4 md:grid-cols-2">
                                    {form.trigger_type === 'keyword' && (
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium">How to match keywords</label>
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
                                    )}
                                    <div>
                                        <label className="mb-1.5 block text-sm font-medium">Only on media type (optional)</label>
                                        <div className="flex gap-3 text-sm">
                                            {['POST', 'REEL', 'STORY', 'AD'].map((t) => (
                                                <label key={t} className="flex items-center gap-1.5">
                                                    <Checkbox
                                                        checked={form.media_filter.includes(t)}
                                                        onChange={(e) => set('media_filter', e.target.checked ? [...form.media_filter, t] : form.media_filter.filter((x) => x !== t))}
                                                    />
                                                    {t.toLowerCase()}
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div>
                                            <p className="text-sm font-medium">Limit to specific posts (optional)</p>
                                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                                Empty = runs on ALL posts. Post-specific automations always override general ones.
                                            </p>
                                        </div>
                                        <Button type="button" variant="secondary" size="sm" onClick={() => loadPosts()} disabled={loadingPosts || loadingMore}>
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
                                                        {p.timestamp && (
                                                            <p className="px-1 pb-0.5 text-[9px] text-neutral-400 dark:text-neutral-500">{new Date(p.timestamp).toLocaleDateString()}</p>
                                                        )}
                                                    </button>
                                                );
                                            })}
                                            {posts.length === 0 && !loadingPosts && (
                                                <p className="col-span-full py-2 text-xs text-neutral-500 dark:text-neutral-400">No posts returned — the account may have no posts yet.</p>
                                            )}
                                            {posts.length > 0 && (
                                                <div className="col-span-full flex items-center justify-center gap-3 py-2">
                                                    <p className="text-xs text-neutral-500 dark:text-neutral-400">{posts.length} post(s) loaded</p>
                                                    {nextCursor && (
                                                        <Button type="button" variant="secondary" size="sm" onClick={() => loadPosts(nextCursor, true)} disabled={loadingMore || loadingPosts}>
                                                            {loadingMore ? 'Loading…' : 'Load more'}
                                                        </Button>
                                                    )}
                                                    {!nextCursor && (
                                                        <p className="text-xs text-neutral-400 dark:text-neutral-500">All posts loaded</p>
                                                    )}
                                                </div>
                                            )}
                                        </div>
                                    )}
                                </div>
                            </div>

                        <div className="mt-5">
                            <StepControls step={step} errors={errors} saving={saving} currentError={currentError} onBack={() => {}} onNext={() => goto(1)} />
                        </div>
                    </Card>
                )}

                {/* STEP 2 — the DM */}
                {step === 1 && (
                    <Card>
                        <p className="mb-1 text-sm font-semibold">What should the DM say?</p>
                        <p className="mb-4 text-xs text-neutral-500 dark:text-neutral-400">
                            Meta allows only ONE private reply per comment — everything goes in this single message.
                        </p>
                        <label className="mb-1.5 block text-sm font-medium">Message</label>
                        <textarea
                            value={form.reply_message}
                            onChange={(e) => set('reply_message', e.target.value)}
                            rows={3}
                            maxLength={1000}
                            className={`w-full rounded-soft border bg-white px-3 py-2 text-sm dark:bg-neutral-900 ${currentError === 'Write the DM message.' ? 'border-red-400' : 'border-neutral-200 dark:border-neutral-700'}`}
                            placeholder="Thanks for your comment!"
                        />
                        <p className="mt-1 text-xs text-neutral-500">Tokens: {'{username}'} — use the commenter&apos;s name in the message.</p>
                        {errors.reply_message && <p className="mt-1 text-xs text-red-500">{errors.reply_message}</p>}

                        <div className="mt-4 flex items-center justify-between rounded-lg border border-neutral-200 px-4 py-3 dark:border-neutral-800">
                            <div>
                                <p className="text-sm font-medium">Ask them to follow you first</p>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                    The DM asks them to follow you and reply with a word like “DONE”. Once they reply, their
                                    link/file is sent automatically. If this is off, everything is sent in the first DM.
                                    {form.delivery.type === 'flow' && ' A DM flow delivery needs this on: the flow starts only after their reply.'}
                                </p>
                            </div>
                            <Toggle checked={form.follow_gate} onChange={(v) => set('follow_gate', v)} />
                        </div>

                        {form.follow_gate && (
                            <div className="mt-3 grid gap-4 md:grid-cols-2">
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium">Follow ask (added to the DM)</label>
                                    <Input
                                        value={form.follow_prompt_message}
                                        onChange={(e) => set('follow_prompt_message', e.target.value)}
                                        placeholder={`Make sure you're following us, then reply with ${form.reply_keyword || 'DONE'}…`}
                                    />
                                    <p className="mt-1 text-xs text-neutral-500">Leave empty to use the default wording.</p>
                                </div>
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium">The word they must reply</label>
                                    <Input value={form.reply_keyword} onChange={(e) => set('reply_keyword', e.target.value)} placeholder="DONE" />
                                    <p className="mt-1 text-xs text-neutral-500">Keep it short and uppercase — e.g. DONE, YES, GO.</p>
                                </div>
                            </div>
                        )}

                        <div className="mt-5 rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800/60">
                            <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                <MessageCircle className="h-3.5 w-3.5" /> DM preview
                            </p>
                            <div className="max-w-md whitespace-pre-wrap rounded-2xl rounded-bl-sm bg-white px-4 py-2.5 text-sm shadow dark:bg-neutral-900">
                                {preview || <span className="text-neutral-400">Start typing to see the DM…</span>}
                            </div>
                        </div>

                        <div className="mt-5">
                            <StepControls step={step} errors={errors} saving={saving} currentError={currentError} onBack={() => setStep(0)} onNext={() => goto(2)} />
                        </div>
                    </Card>
                )}

                {/* STEP 3 — delivery */}
                {step === 2 && (
                    <Card>
                        <p className="mb-1 text-sm font-semibold">What do they receive?</p>
                        <p className="mb-4 text-xs text-neutral-500 dark:text-neutral-400">
                            {form.delivery.type === 'flow'
                                ? `After they reply "${(form.reply_keyword || 'DONE').trim()}", the flow takes over.`
                                : form.follow_gate
                                    ? `After they reply "${(form.reply_keyword || 'DONE').trim()}", we send this.`
                                    : 'This is included in the first DM.'}
                        </p>
                        <div className="grid gap-3 md:grid-cols-3">
                            {DELIVERY_CHOICES.map((choice) => (
                                <button
                                    key={choice.value}
                                    type="button"
                                    onClick={() => setDelivery('type', choice.value)}
                                    className={`rounded-xl border-2 p-4 text-left transition ${
                                        form.delivery.type === choice.value
                                            ? 'border-brand-500 bg-brand-50 ring-2 ring-brand-500/30 dark:bg-brand-950/40'
                                            : 'border-neutral-200 hover:border-neutral-300 dark:border-neutral-800 dark:hover:border-neutral-700'
                                    }`}
                                >
                                    <p className="text-sm font-semibold">{choice.label}</p>
                                    <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{choice.hint}</p>
                                </button>
                            ))}
                        </div>

                        {form.delivery.type === 'flow' && (
                            <div className="mt-4 rounded-lg border border-violet-200 bg-violet-50 p-4 dark:border-violet-900 dark:bg-violet-950/30">
                                <label className="mb-1.5 block text-sm font-medium text-violet-800 dark:text-violet-200">Which DM flow should run?</label>
                                {flows.length > 0 ? (
                                    <>
                                        <Select
                                            value={form.delivery.flow_id ?? ''}
                                            onChange={(e) => setDelivery('flow_id', e.target.value ? Number(e.target.value) : null)}
                                            options={flows.map((f) => ({ value: f.id, label: f.name }))}
                                            placeholder="Pick a flow…"
                                        />
                                        <p className="mt-2 text-xs text-violet-700 dark:text-violet-300">
                                            After they reply, the flow takes over — its first message goes out with YES/NO-style next steps. Edit or add flows under Instagram → DM Flows.
                                        </p>
                                    </>
                                ) : (
                                    <p className="text-xs text-violet-700 dark:text-violet-300">
                                        No flows yet. Create one first under <a href={route('client.instagram.flows.index')} className="font-medium underline">Instagram → DM Flows</a> — it takes a minute — then come back and pick it here.
                                    </p>
                                )}
                                {errors['delivery.flow_id'] && <p className="mt-1 text-xs text-red-500">{errors['delivery.flow_id']}</p>}
                            </div>
                        )}

                        {form.delivery.type !== 'flow' && (
                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                            {(form.delivery.type === 'link' || form.delivery.type === 'file') && (
                                <div className="md:col-span-2">
                                    <label className="mb-1.5 block text-sm font-medium">
                                        {form.delivery.type === 'file' ? 'File URL (public download link)' : 'Link to send'}
                                    </label>
                                    <Input value={form.delivery.url} onChange={(e) => setDelivery('url', e.target.value)} placeholder="https://…" />
                                    {errors['delivery.url'] && <p className="mt-1 text-xs text-red-500">{errors['delivery.url']}</p>}
                                </div>
                            )}
                            {form.delivery.type === 'file' && (
                                <div>
                                    <label className="mb-1.5 block text-sm font-medium">File name (optional)</label>
                                    <Input value={form.delivery.filename} onChange={(e) => setDelivery('filename', e.target.value)} placeholder="guide.pdf" />
                                </div>
                            )}
                            <div className="md:col-span-2">
                                <label className="mb-1.5 block text-sm font-medium">
                                    {form.delivery.type === 'text' ? 'The message' : 'A line to introduce it (optional)'}
                                </label>
                                <Input value={form.delivery.text} onChange={(e) => setDelivery('text', e.target.value)} placeholder={form.delivery.type === 'text' ? 'Your coupon code is WELCOME10' : 'Here it is as promised:'} />
                                {errors['delivery.text'] && <p className="mt-1 text-xs text-red-500">{errors['delivery.text']}</p>}
                            </div>
                        </div>
                        )}

                        <div className="mt-5 rounded-lg border border-neutral-200 p-4 dark:border-neutral-800">
                            <p className="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                                <MessageCircle className="h-3.5 w-3.5" /> Final DM preview
                            </p>
                            <div className="max-w-md whitespace-pre-wrap rounded-2xl rounded-bl-sm bg-white px-4 py-2.5 text-sm shadow dark:bg-neutral-900">
                                {preview || <span className="text-neutral-400">Start typing to see the DM…</span>}
                            </div>
                        </div>

                        <div className="mt-5 rounded-lg bg-neutral-50 p-4 text-sm text-neutral-600 dark:bg-neutral-800/60 dark:text-neutral-400">
                            <p><span className="font-medium text-neutral-900 dark:text-neutral-100">Ready to save.</span> When {triggerLabel} arrive, the bot DMs the commenter. {deliveryLabel}.</p>
                        </div>

                        <div className="mt-5">
                            <StepControls step={step} errors={errors} saving={saving} currentError={currentError} onBack={() => setStep(1)} onNext={() => {}} />
                        </div>
                    </Card>
                )}
            </form>
        </ClientLayout>
    );
}
