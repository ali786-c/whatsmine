import { Head, useForm, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { Brain, Trash2, Plus, Sparkles, ShieldCheck, Zap } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

const KIND_STYLES = {
    fact: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    preference: 'bg-pink-100 text-pink-700 dark:bg-pink-900/40 dark:text-pink-300',
    policy: 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
    order_info: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    business: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',
};

const KINDS = ['fact', 'preference', 'policy', 'order_info', 'business'];

function ToggleSwitch({ checked, onChange }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            onClick={() => onChange(!checked)}
            className={`relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none ${checked ? 'bg-brand-600' : 'bg-neutral-200 dark:bg-neutral-700'}`}
        >
            <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition duration-200 ${checked ? 'translate-x-4' : 'translate-x-0'}`} />
        </button>
    );
}

export default function AiMemoriesIndex({ memories, learningEnabled, maxMemories, extractEvery }) {
    const { t } = useTranslation();
    const [showForm, setShowForm] = useState(false);
    const [filter, setFilter] = useState('all');

    const addForm = useForm({ kind: 'fact', content: '' });
    const learning = Boolean(learningEnabled);

    const submit = (e) => {
        e.preventDefault();
        addForm.post(route('client.ai.memories.store'), {
            onSuccess: () => {
                addForm.reset();
                setShowForm(false);
            },
        });
    };

    const remove = (memory) => {
        router.delete(route('client.ai.memories.destroy', memory.id), { preserveScroll: true });
    };

    const toggleLearning = () => {
        router.post(route('client.ai.memories.toggle'), {}, { preserveScroll: true });
    };

    const visible = filter === 'all' ? memories : memories.filter((m) => m.kind === filter);

    return (
        <ClientLayout>
            <Head title={t('ai.memory_title')} />

            <div className="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
                {/* Header */}
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <div className="h-10 w-10 rounded-xl bg-brand-100 dark:bg-brand-900/40 flex items-center justify-center shrink-0">
                            <Brain className="h-5 w-5 text-brand-600 dark:text-brand-300" />
                        </div>
                        <div>
                            <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.memory_title')}</h1>
                            <p className="text-sm text-neutral-500 dark:text-neutral-400 mt-0.5">{t('ai.memory_subtitle')}</p>
                        </div>
                    </div>

                    <div className="flex items-center gap-2">
                        <span className={`text-xs font-medium ${learning ? 'text-green-600 dark:text-green-400' : 'text-neutral-400'}`}>
                            {learning ? t('ai.memory_learning_on') : t('ai.memory_learning_off')}
                        </span>
                        <ToggleSwitch checked={learning} onChange={toggleLearning} />
                    </div>
                </div>

                {/* Stats */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 flex items-center gap-3">
                        <Brain className="h-4 w-4 text-brand-500 shrink-0" />
                        <div className="text-sm">
                            <span className="font-semibold text-neutral-900 dark:text-neutral-100">{memories.length}</span>
                            <span className="text-neutral-500 dark:text-neutral-400"> / {maxMemories} {t('ai.memory_count_label')}</span>
                        </div>
                    </div>
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 flex items-center gap-3">
                        <Zap className="h-4 w-4 text-amber-500 shrink-0" />
                        <p className="text-sm text-neutral-600 dark:text-neutral-300">{t('ai.memory_cadence', { n: extractEvery })}</p>
                    </div>
                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 flex items-center gap-3">
                        <ShieldCheck className="h-4 w-4 text-green-500 shrink-0" />
                        <p className="text-sm text-neutral-600 dark:text-neutral-300">{t('ai.memory_background_note')}</p>
                    </div>
                </div>

                {/* Toolbar */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex flex-wrap gap-1.5">
                        {['all', ...KINDS].map((k) => (
                            <button
                                key={k}
                                type="button"
                                onClick={() => setFilter(k)}
                                className={`px-2.5 py-1 rounded-lg text-xs font-medium transition ${
                                    filter === k
                                        ? 'bg-brand-600 text-white'
                                        : 'bg-neutral-100 dark:bg-neutral-800 text-neutral-600 dark:text-neutral-300 hover:bg-neutral-200 dark:hover:bg-neutral-700'
                                }`}
                            >
                                {k === 'all' ? t('ai.memory_filter_all') : t(`ai.memory_kind_${k}`)}
                            </button>
                        ))}
                    </div>

                    <button
                        type="button"
                        onClick={() => setShowForm((v) => !v)}
                        className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-brand-700 transition"
                    >
                        <Plus className="h-3.5 w-3.5" />
                        {t('ai.memory_add')}
                    </button>
                </div>

                {/* Add form */}
                {showForm && (
                    <form onSubmit={submit} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 space-y-3">
                        <div className="grid grid-cols-1 sm:grid-cols-[180px_1fr] gap-3">
                            <div>
                                <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300 mb-1">{t('ai.memory_kind')}</label>
                                <select
                                    value={addForm.data.kind}
                                    onChange={(e) => addForm.setData('kind', e.target.value)}
                                    className="w-full rounded-lg border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm"
                                >
                                    {KINDS.map((k) => (
                                        <option key={k} value={k}>{t(`ai.memory_kind_${k}`)}</option>
                                    ))}
                                </select>
                            </div>
                            <div>
                                <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300 mb-1">{t('ai.memory_content')}</label>
                                <textarea
                                    value={addForm.data.content}
                                    onChange={(e) => addForm.setData('content', e.target.value)}
                                    rows={2}
                                    maxLength={300}
                                    placeholder={t('ai.memory_placeholder')}
                                    className="w-full rounded-lg border-neutral-300 dark:border-neutral-600 dark:bg-neutral-800 text-sm"
                                />
                            </div>
                        </div>
                        {addForm.errors.content && <p className="text-xs text-red-500">{addForm.errors.content}</p>}
                        <div className="flex justify-end gap-2">
                            <button type="button" onClick={() => setShowForm(false)} className="px-3 py-1.5 text-xs rounded-lg border border-neutral-200 dark:border-neutral-600 text-neutral-600 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                {t('ai.memory_cancel')}
                            </button>
                            <button type="submit" disabled={addForm.processing} className="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs rounded-lg bg-brand-600 text-white font-semibold hover:bg-brand-700 disabled:opacity-50 transition">
                                <Sparkles className="h-3.5 w-3.5" />
                                {t('ai.memory_save')}
                            </button>
                        </div>
                    </form>
                )}

                {/* List */}
                {visible.length === 0 ? (
                    <EmptyState
                        icon={<Brain className="h-8 w-8" />}
                        title={t('ai.memory_empty_title')}
                        description={t('ai.memory_empty_desc')}
                    />
                ) : (
                    <ul className="space-y-2">
                        {visible.map((m) => (
                            <li
                                key={m.id}
                                className="group rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-4 py-3 flex items-start gap-3"
                            >
                                <span className={`shrink-0 mt-0.5 px-2 py-0.5 rounded-md text-[10px] font-semibold uppercase tracking-wide ${KIND_STYLES[m.kind] ?? KIND_STYLES.fact}`}>
                                    {t(`ai.memory_kind_${m.kind}`)}
                                </span>
                                <p className="flex-1 text-sm text-neutral-800 dark:text-neutral-200 leading-relaxed">{m.content}</p>
                                <div className="shrink-0 flex items-center gap-2">
                                    <span className="text-[10px] text-neutral-400 font-mono" title={t('ai.memory_usefulness')}>
                                        {m.source === 'manual' ? t('ai.memory_manual') : '×'}{m.source === 'manual' ? '' : m.usefulness} · {t('ai.memory_used', { n: m.use_count })}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={() => remove(m)}
                                        title={t('ai.memory_delete')}
                                        className="opacity-0 group-hover:opacity-100 transition text-neutral-400 hover:text-red-500"
                                    >
                                        <Trash2 className="h-4 w-4" />
                                    </button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </ClientLayout>
    );
}
