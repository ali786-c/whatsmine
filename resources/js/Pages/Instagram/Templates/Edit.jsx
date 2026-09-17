import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ClientLayout from '@/Layouts/ClientLayout';
import Button from '@/Components/ui/Button';
import { IgTemplateBody, IgDmPreview } from '@/Components/Instagram/IgTemplatePreview';
import { Plus, X, Loader2, Send, Save } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import {
    emptyIgButton, emptyIgCard, emptyIgDefinition,
    igTemplateValid, buildIgTemplateMessage, igTemplateSummary,
} from '@/Utils/igTemplate';

/** Shared button rows editor — used by both template types */
function ButtonListEditor({ t, buttons: rawButtons, onChange, small = false }) {
    const buttons = (rawButtons ?? []).filter(Boolean);
    const update = (i, patch) => onChange(buttons.map((b, j) => j === i ? { ...b, ...patch } : b));
    const remove = (i) => onChange(buttons.filter((_, j) => j !== i));

    return (
        <div className="space-y-2">
            <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{t('inbox.ig_tpl_buttons_n')}</p>
            {buttons.map((b, i) => (
                <div key={i} className={`rounded-xl border border-neutral-200 dark:border-neutral-700 ${small ? 'p-2' : 'p-3'} space-y-2`}>
                    <div className="flex items-center gap-2">
                        <select value={b.type ?? 'web_url'} onChange={e => update(i, { type: e.target.value })}
                            className="rounded-lg border border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-2 py-1 text-xs">
                            <option value="web_url">{t('inbox.ig_tpl_btn_url')}</option>
                            <option value="postback">{t('inbox.ig_tpl_btn_postback')}</option>
                        </select>
                        <input type="text" value={b.title ?? ''} maxLength={20} onChange={e => update(i, { title: e.target.value })}
                            placeholder={t('inbox.ig_tpl_btn_title')}
                            className="flex-1 min-w-0 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-2 py-1 text-xs" />
                        <button type="button" onClick={() => remove(i)}
                            className="text-neutral-400 hover:text-red-500 shrink-0"><X className="h-4 w-4" /></button>
                    </div>
                    {(b.type ?? 'web_url') === 'web_url' ? (
                        <input type="url" value={b.url ?? ''} onChange={e => update(i, { url: e.target.value })}
                            placeholder={t('inbox.ig_tpl_btn_url_field')}
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-2 py-1 text-xs" />
                    ) : (
                        <input type="text" value={b.payload ?? ''} onChange={e => update(i, { payload: e.target.value })}
                            placeholder={t('inbox.ig_tpl_btn_payload_field')}
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800 px-2 py-1 text-xs" />
                    )}
                </div>
            ))}
            {buttons.length < 3 && (
                <button type="button" onClick={() => onChange([...buttons, emptyIgButton()])}
                    className="w-full rounded-lg border border-dashed border-neutral-300 dark:border-neutral-600 py-1.5 text-xs text-neutral-500 hover:bg-neutral-50 dark:hover:bg-neutral-800">
                    + {t('inbox.ig_tpl_add_button')}
                </button>
            )}
        </div>
    );
}

export default function InstagramTemplatesEdit({ template = null }) {
    const { t } = useTranslation();
    const [name, setName] = useState(template?.name ?? '');
    const [type, setType] = useState(template?.type ?? 'button');
    const [definition, setDefinition] = useState(
        template?.definition ? JSON.parse(JSON.stringify(template.definition)) : emptyIgDefinition(template?.type ?? 'button')
    );
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');

    const valid = igTemplateValid(type, definition);
    const canSave = valid && name.trim() !== '' && !saving;
    const def = definition ?? {};

    const setDef = (patch) => setDefinition(d => ({ ...d, ...patch }));

    const graphMessage = useMemo(() => buildIgTemplateMessage(type, definition), [type, definition]);

    const save = () => {
        setError('');
        setSaving(true);
        const payload = { name, type, definition };
        const onFinish = () => setSaving(false);
        const onSuccess = () => router.visit(route('client.instagram.templates.gallery'));

        if (template?.id) {
            router.put(route('client.instagram.templates.update', template.id), payload, { onSuccess, onFinish, onError: e => setError(Object.values(e)[0] ?? 'Save failed') });
        } else {
            router.post(route('client.instagram.templates.store'), payload, { onSuccess, onFinish, onError: e => setError(Object.values(e)[0] ?? 'Save failed') });
        }
    };

    return (
        <ClientLayout>
            <Head title={template ? `Edit ${template.name}` : 'New Instagram Template'} />

            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-semibold">{template ? `Edit template` : t('nav.instagram_templates')}</h1>
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            {type === 'button' ? t('inbox.ig_tpl_buttons') : t('inbox.ig_tpl_carousel')}
                            {template ? '' : ' — no Meta approval needed'}
                            {definition ? ` · ${igTemplateSummary(type, definition)}` : ''}
                        </p>
                    </div>
                    <div className="flex items-center gap-2">
                        <Button variant="outline" onClick={() => router.visit(route('client.instagram.templates.gallery'))}>
                            {t('inbox.ig_tpl_back')}
                        </Button>
                        <Button onClick={save} disabled={!canSave}>
                            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                            {t('inbox.ig_tpl_save')}
                        </Button>
                    </div>
                </div>

                <div className="grid gap-5 lg:grid-cols-[1fr_280px]">
                    {/* Left: form */}
                    <div className="space-y-4">
                        {/* Type toggle */}
                        {!template && (
                            <div className="flex gap-2">
                                {[['button', t('inbox.ig_tpl_buttons')], ['generic', t('inbox.ig_tpl_carousel')]].map(([ty, label]) => (
                                    <button key={ty} type="button" onClick={() => { setType(ty); setDefinition(emptyIgDefinition(ty)); }}
                                        className={`flex-1 rounded-xl px-3 py-2 text-sm font-medium border transition ${
                                            type === ty
                                                ? 'bg-brand-50 border-brand-300 text-brand-700 dark:bg-brand-900/30 dark:border-brand-700 dark:text-brand-300'
                                                : 'border-neutral-200 text-neutral-500 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800'
                                        }`}>
                                        {label}
                                    </button>
                                ))}
                            </div>
                        )}

                        <div>
                            <label className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 mb-1 block">{t('inbox.ig_tpl_name')}</label>
                            <input type="text" value={name} onChange={e => setName(e.target.value)}
                                placeholder={t('inbox.ig_tpl_name_placeholder')}
                                className="w-full max-w-md rounded-xl border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500" />
                        </div>

                        {/* BUTTON template form */}
                        {type === 'button' && (
                            <div className="space-y-4">
                                <div>
                                    <label className="text-[10px] font-bold uppercase tracking-wider text-neutral-400 mb-1 block">{t('inbox.ig_tpl_text')}</label>
                                    <textarea value={def.text ?? ''} onChange={e => setDef({ text: e.target.value })} rows={3} maxLength={640}
                                        className="w-full rounded-xl border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none" />
                                    <p className="text-[11px] text-neutral-400 mt-1">{t('inbox.ig_tpl_text_hint')} · {(def.text ?? '').length}/640</p>
                                </div>
                                <ButtonListEditor t={t} buttons={def.buttons ?? []} onChange={buttons => setDef({ buttons })} />
                            </div>
                        )}

                        {/* GENERIC carousel form */}
                        {type === 'generic' && (
                            <div className="space-y-3">
                                <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400">{t('inbox.ig_tpl_cards')} ({(def.elements ?? []).length}/10)</p>
                                {(def.elements ?? []).map((el, i) => (
                                    <div key={i} className="rounded-xl border border-neutral-200 dark:border-neutral-700 p-3 space-y-2">
                                        <div className="flex items-center justify-between">
                                            <span className="text-xs font-bold text-neutral-500">{t('inbox.ig_tpl_card', { n: i + 1 })}</span>
                                            {(def.elements ?? []).length > 1 && (
                                                <button type="button" onClick={() => setDef({ elements: def.elements.filter((_, j) => j !== i) })}
                                                    className="text-neutral-400 hover:text-red-500"><X className="h-4 w-4" /></button>
                                            )}
                                        </div>
                                        <input type="text" value={el.title ?? ''} maxLength={80}
                                            onChange={e => setDef({ elements: def.elements.map((x, j) => j === i ? { ...x, title: e.target.value } : x) })}
                                            placeholder={t('inbox.ig_tpl_card_title')}
                                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-sm" />
                                        <input type="text" value={el.subtitle ?? ''} maxLength={80}
                                            onChange={e => setDef({ elements: def.elements.map((x, j) => j === i ? { ...x, subtitle: e.target.value } : x) })}
                                            placeholder={t('inbox.ig_tpl_card_subtitle')}
                                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-sm" />
                                        <input type="url" value={el.image_url ?? ''}
                                            onChange={e => setDef({ elements: def.elements.map((x, j) => j === i ? { ...x, image_url: e.target.value } : x) })}
                                            placeholder={t('inbox.ig_tpl_card_image')}
                                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-sm" />
                                        <ButtonListEditor t={t} small buttons={el.buttons ?? []}
                                            onChange={buttons => setDef({ elements: def.elements.map((x, j) => j === i ? { ...x, buttons } : x) })} />
                                    </div>
                                ))}
                                {(def.elements ?? []).length < 10 && (
                                    <button type="button" onClick={() => setDef({ elements: [...(def.elements ?? []), emptyIgCard()] })}
                                        className="w-full rounded-xl border border-dashed border-neutral-300 dark:border-neutral-600 py-2 text-sm text-brand-600 hover:bg-brand-50 dark:text-brand-400 dark:hover:bg-brand-900/20">
                                        + {t('inbox.ig_tpl_add_card')}
                                    </button>
                                )}
                            </div>
                        )}

                        {error && <p className="text-sm text-red-500 bg-red-50 dark:bg-red-900/20 rounded-lg px-3 py-2">{error}</p>}
                        {!valid && <p className="text-xs text-neutral-400">{t('inbox.ig_tpl_needs_card')}</p>}
                    </div>

                    {/* Right: live preview — a mock Instagram DM screen */}
                    <div className="space-y-3">
                        <p className="text-[10px] font-bold uppercase tracking-wider text-neutral-400">Live preview — customer&rsquo;s Instagram</p>
                        <div className="sticky top-4">
                            <IgDmPreview payload={graphMessage.attachment.payload} username="yourbusiness" />
                        </div>
                    </div>
                </div>
            </div>
        </ClientLayout>
    );
}
