import { useRef, useState } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Button, Card, Tabs } from '@/Components/ui';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useTranslation } from 'react-i18next';
import { Upload, X, Image, Globe, Palette, Settings2, Code2, Flame, Bot, Route } from 'lucide-react';

// ─── General Settings Tab ─────────────────────────────────────────────────────

function GeneralTab({ general, flash }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors } = useForm({
        app_name:      general?.app_name      ?? '',
        app_tagline:   general?.app_tagline   ?? '',
        support_email: general?.support_email ?? '',
        primary_color: general?.primary_color ?? '#467235',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.settings.general.update'), { preserveScroll: true });
    };

    return (
        <div className="space-y-6">
            {flash?.success && (
                <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                    {flash.success}
                </div>
            )}

            <form onSubmit={submit}>
                <Card>
                    <Card.Body className="space-y-5">
                        <div className="flex items-center gap-3 pb-4 border-b border-neutral-100 dark:border-neutral-800">
                            <Globe className="h-5 w-5 text-brand-500" />
                            <div>
                                <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('settings.site_information')}</h3>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('settings.site_info_desc')}</p>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div className="space-y-1">
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('settings.app_name')}</label>
                                <input
                                    type="text"
                                    value={data.app_name}
                                    onChange={(e) => setData('app_name', e.target.value)}
                                    placeholder={t('settings.app_name_placeholder')}
                                    className="w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                />
                                {errors.app_name && <p className="text-xs text-red-500">{errors.app_name}</p>}
                            </div>

                            <div className="space-y-1">
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('settings.support_email')}</label>
                                <input
                                    type="email"
                                    value={data.support_email}
                                    onChange={(e) => setData('support_email', e.target.value)}
                                    placeholder="support@example.com"
                                    className="w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                />
                                {errors.support_email && <p className="text-xs text-red-500">{errors.support_email}</p>}
                            </div>

                            <div className="space-y-1 sm:col-span-2">
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('settings.tagline')}</label>
                                <input
                                    type="text"
                                    value={data.app_tagline}
                                    onChange={(e) => setData('app_tagline', e.target.value)}
                                    placeholder={t('settings.tagline_placeholder')}
                                    className="w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                />
                                {errors.app_tagline && <p className="text-xs text-red-500">{errors.app_tagline}</p>}
                            </div>
                        </div>

                        <div className="flex items-center gap-3 pb-4 pt-2 border-b border-neutral-100 dark:border-neutral-800">
                            <Palette className="h-5 w-5 text-brand-500" />
                            <div>
                                <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('settings.appearance')}</h3>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('settings.appearance_desc')}</p>
                            </div>
                        </div>

                        <div className="space-y-1">
                            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('settings.primary_brand_color')}</label>
                            <div className="flex items-center gap-3">
                                <input
                                    type="color"
                                    value={data.primary_color}
                                    onChange={(e) => setData('primary_color', e.target.value)}
                                    className="h-10 w-16 cursor-pointer rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 p-1"
                                />
                                <input
                                    type="text"
                                    value={data.primary_color}
                                    onChange={(e) => setData('primary_color', e.target.value)}
                                    placeholder="#467235"
                                    maxLength={7}
                                    className="w-32 rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                />
                                <span className="text-xs text-neutral-500 dark:text-neutral-400">{t('settings.hex_color_hint')}</span>
                            </div>
                            {errors.primary_color && <p className="text-xs text-red-500">{errors.primary_color}</p>}
                        </div>

                        <div className="flex justify-end pt-2">
                            <Button type="submit" variant="primary" disabled={processing}>
                                {processing ? t('settings.saving') : t('settings.save_general')}
                            </Button>
                        </div>
                    </Card.Body>
                </Card>
            </form>

            <Card>
                <Card.Body className="space-y-6">
                    <div className="flex items-center gap-3 pb-4 border-b border-neutral-100 dark:border-neutral-800">
                        <Image className="h-5 w-5 text-brand-500" />
                        <div>
                            <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('settings.logo_favicon')}</h3>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('settings.logo_favicon_desc')}</p>
                        </div>
                    </div>

                    <ImageUploadWidget
                        label={t('settings.app_logo')}
                        description={t('settings.app_logo_desc')}
                        accept="image/png,image/jpeg,image/gif,image/svg+xml,image/webp"
                        currentUrl={general?.logo_url}
                        uploadRoute="admin.settings.logo.upload"
                        deleteRoute="admin.settings.logo.delete"
                        fieldName="logo"
                    />

                    <div className="border-t border-neutral-100 dark:border-neutral-800 pt-5">
                        <ImageUploadWidget
                            label={t('settings.favicon')}
                            description={t('settings.favicon_desc')}
                            accept="image/x-icon,image/vnd.microsoft.icon,image/png,image/svg+xml,image/gif,image/webp"
                            currentUrl={general?.favicon_url}
                            uploadRoute="admin.settings.favicon.upload"
                            deleteRoute="admin.settings.favicon.delete"
                            fieldName="favicon"
                        />
                    </div>
                </Card.Body>
            </Card>

            <Card>
                <Card.Body>
                    <p className="text-sm text-neutral-500 dark:text-neutral-400">
                        <strong className="text-neutral-700 dark:text-neutral-300">{t('settings.logo_usage_title')}</strong>
                        {' '}{t('settings.logo_usage_hint')}
                    </p>
                </Card.Body>
            </Card>
        </div>
    );
}

// ─── Image Upload Widget ───────────────────────────────────────────────────────

function ImageUploadWidget({ label, description, accept, currentUrl, uploadRoute, deleteRoute, fieldName }) {
    const { t } = useTranslation();
    const fileRef = useRef(null);
    const [preview, setPreview] = useState(null);
    const [uploading, setUploading] = useState(false);
    const [deleting, setDeleting] = useState(false);

    const handleFile = (file) => {
        if (!file) return;
        setPreview(URL.createObjectURL(file));
        const fd = new FormData();
        fd.append(fieldName, file);
        setUploading(true);
        router.post(route(uploadRoute), fd, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => {
                setUploading(false);
                setPreview(null);
            },
        });
    };

    const handleDelete = () => {
        setDeleting(true);
        router.delete(route(deleteRoute), {
            preserveScroll: true,
            onFinish: () => setDeleting(false),
        });
    };

    const displayUrl = preview || currentUrl;

    return (
        <div className="space-y-3">
            <div>
                <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</p>
                <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">{description}</p>
            </div>

            <div className="flex items-start gap-4">
                {/* Preview box */}
                <div className="relative flex-shrink-0 w-24 h-24 rounded-soft-lg border-2 border-dashed border-neutral-300 dark:border-neutral-600 bg-neutral-50 dark:bg-neutral-800/50 flex items-center justify-center overflow-hidden">
                    {displayUrl ? (
                        <>
                            <img src={displayUrl} alt={label} className="w-full h-full object-contain p-1" />
                            {!uploading && currentUrl && (
                                <button
                                    type="button"
                                    onClick={handleDelete}
                                    disabled={deleting}
                                    className="absolute top-1 right-1 rounded-full bg-red-500 text-white p-0.5 hover:bg-red-600 transition"
                                >
                                    <X className="h-3 w-3" />
                                </button>
                            )}
                        </>
                    ) : (
                        <Image className="h-8 w-8 text-neutral-300 dark:text-neutral-600" />
                    )}
                    {(uploading || deleting) && (
                        <div className="absolute inset-0 bg-white/60 dark:bg-neutral-900/60 flex items-center justify-center">
                            <svg className="animate-spin h-5 w-5 text-brand-500" viewBox="0 0 24 24" fill="none">
                                <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4" />
                                <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z" />
                            </svg>
                        </div>
                    )}
                </div>

                {/* Actions */}
                <div className="flex flex-col gap-2 justify-center">
                    <button
                        type="button"
                        onClick={() => fileRef.current?.click()}
                        disabled={uploading || deleting}
                        className="inline-flex items-center gap-2 rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-700 transition disabled:opacity-50"
                    >
                        <Upload className="h-4 w-4" />
                        {currentUrl ? t('settings.replace') : t('settings.upload')}
                    </button>
                    {currentUrl && (
                        <button
                            type="button"
                            onClick={handleDelete}
                            disabled={deleting || uploading}
                            className="inline-flex items-center gap-2 rounded-soft border border-red-200 dark:border-red-900 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition disabled:opacity-50"
                        >
                            <X className="h-4 w-4" />
                            {t('settings.remove')}
                        </button>
                    )}
                    <input
                        ref={fileRef}
                        type="file"
                        accept={accept}
                        className="hidden"
                        onChange={(e) => handleFile(e.target.files?.[0])}
                    />
                </div>
            </div>
        </div>
    );
}

// ─── Advanced Tab ─────────────────────────────────────────────────────────────

function AdvancedTab({ settingsByGroup, flash }) {
    const { t } = useTranslation();
    const flat = Object.entries(settingsByGroup).flatMap(([group, items]) =>
        items.map((s) => ({ ...s, group }))
    );
    const { data, setData, put, processing } = useForm({ settings: flat });

    if (flat.length === 0) {
        return (
            <Card>
                <Card.Body>
                    <div className="text-center py-8">
                        <Code2 className="h-10 w-10 mx-auto text-neutral-300 dark:text-neutral-600 mb-3" />
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">{t('settings.no_advanced')}</p>
                        <p className="text-xs text-neutral-400 dark:text-neutral-500 mt-1">{t('settings.no_advanced_desc')}</p>
                    </div>
                </Card.Body>
            </Card>
        );
    }

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                put(route('admin.settings.update'), { preserveScroll: true });
            }}
            className="space-y-6"
        >
            {flash?.success && (
                <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                    {flash.success}
                </div>
            )}

            {Object.entries(
                data.settings.reduce((acc, s, i) => {
                    const g = s.group || 'Ungrouped';
                    if (!acc[g]) acc[g] = [];
                    acc[g].push({ ...s, _index: i });
                    return acc;
                }, {})
            ).map(([group, items]) => (
                <Card key={group}>
                    <Card.Body className="space-y-3">
                        <div className="flex items-center gap-2 pb-3 border-b border-neutral-100 dark:border-neutral-800">
                            <Settings2 className="h-4 w-4 text-neutral-400" />
                            <h3 className="text-sm font-semibold text-neutral-700 dark:text-neutral-300 capitalize">{group}</h3>
                        </div>
                        {items.map((s) => {
                            const i = s._index;
                            return (
                                <div key={s.id || i} className="flex flex-wrap gap-2 items-center border-b border-neutral-100 dark:border-neutral-800 pb-2 last:border-0 last:pb-0">
                                    <span className="font-mono text-xs w-44 text-neutral-600 dark:text-neutral-400 truncate" title={s.key}>{s.key}</span>
                                    <input
                                        type={s.is_secret ? 'password' : 'text'}
                                        value={s.value}
                                        onChange={(e) => {
                                            const next = [...data.settings];
                                            next[i] = { ...next[i], value: e.target.value };
                                            setData('settings', next);
                                        }}
                                        placeholder={s.is_secret ? t('admin.secret_value') : t('admin.value_label')}
                                        className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-1.5 text-sm flex-1 min-w-[180px] focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                    />
                                    <label className="flex items-center gap-1 text-xs text-neutral-600 dark:text-neutral-400">
                                        <input
                                            type="checkbox"
                                            checked={s.is_secret}
                                            onChange={(e) => {
                                                const n = [...data.settings];
                                                n[i] = { ...n[i], is_secret: e.target.checked };
                                                setData('settings', n);
                                            }}
                                            className="rounded border-neutral-300 dark:border-neutral-600 text-brand-500"
                                        />
                                        {t('settings.secret')}
                                    </label>
                                    <input
                                        type="text"
                                        value={s.group ?? ''}
                                        onChange={(e) => {
                                            const n = [...data.settings];
                                            n[i] = { ...n[i], group: e.target.value };
                                            setData('settings', n);
                                        }}
                                        placeholder={t('admin.group_label')}
                                        className="rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-2 py-1.5 w-24 text-xs focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                                    />
                                </div>
                            );
                        })}
                    </Card.Body>
                </Card>
            ))}

            <div className="flex justify-end">
                <Button type="submit" variant="primary" disabled={processing}>
                    {processing ? t('settings.saving') : t('settings.save_advanced')}
                </Button>
            </div>
        </form>
    );
}

// ─── Firebase Tab ─────────────────────────────────────────────────────────────

function FirebaseTab({ firebase, flash }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors } = useForm({
        firebase_enabled:     firebase?.enabled    ? 'true' : 'false',
        firebase_api_key:     firebase?.apiKey     ?? '',
        firebase_auth_domain: firebase?.authDomain ?? '',
        firebase_project_id:  firebase?.projectId  ?? '',
        firebase_app_id:      firebase?.appId      ?? '',
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.settings.firebase.update'), { preserveScroll: true });
    };

    const field = (label, key, description, placeholder = '') => (
        <div className="space-y-1">
            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</label>
            {description && <p className="text-xs text-neutral-400 dark:text-neutral-500">{description}</p>}
            <input
                type="text"
                value={data[key]}
                onChange={(e) => setData(key, e.target.value)}
                placeholder={placeholder}
                className="w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/20"
            />
            {errors[key] && <p className="text-xs text-red-500">{errors[key]}</p>}
        </div>
    );

    return (
        <form onSubmit={submit} className="space-y-6">
            {flash?.success && (
                <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                    {flash.success}
                </div>
            )}

            <Card>
                <Card.Body className="space-y-5">
                    <div className="flex items-center gap-3 pb-4 border-b border-neutral-100 dark:border-neutral-800">
                        <Flame className="h-5 w-5 text-orange-500" />
                        <div>
                            <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{t('settings.firebase_auth')}</h3>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                {t('settings.firebase_auth_desc')}
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center justify-between rounded-soft border border-neutral-200 dark:border-neutral-700 px-4 py-3">
                        <div>
                            <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('settings.enable_firebase')}</p>
                            <p className="text-xs text-neutral-400 dark:text-neutral-500 mt-0.5">{t('settings.enable_firebase_desc')}</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={data.firebase_enabled === 'true'}
                            onClick={() => setData('firebase_enabled', data.firebase_enabled === 'true' ? 'false' : 'true')}
                            className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500/20 ${
                                data.firebase_enabled === 'true' ? 'bg-brand-500' : 'bg-neutral-300 dark:bg-neutral-600'
                            }`}
                        >
                            <span
                                className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
                                    data.firebase_enabled === 'true' ? 'translate-x-6' : 'translate-x-1'
                                }`}
                            />
                        </button>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        {field(t('settings.api_key'), 'firebase_api_key', t('settings.firebase_api_key_desc'), 'AIzaSy...')}
                        {field(t('settings.auth_domain'), 'firebase_auth_domain', t('settings.firebase_auth_domain_desc'), 'your-project.firebaseapp.com')}
                        {field(t('settings.project_id'), 'firebase_project_id', t('settings.firebase_project_id_desc'), 'your-project-id')}
                        {field(t('settings.app_id'), 'firebase_app_id', t('settings.firebase_app_id_desc'), '1:123456:web:abc...')}
                    </div>

                    <div className="rounded-soft border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 px-4 py-3 text-xs text-amber-700 dark:text-amber-300 space-y-1">
                        <p className="font-semibold">{t('settings.setup_checklist')}</p>
                        <ol className="list-decimal list-inside space-y-0.5 mt-1">
                            <li>{t('settings.firebase_step_1')}</li>
                            <li>{t('settings.firebase_step_2')}</li>
                            <li>{t('settings.firebase_step_3')}</li>
                        </ol>
                    </div>
                </Card.Body>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" variant="primary" disabled={processing}>
                    {processing ? t('settings.saving') : t('settings.save_firebase')}
                </Button>
            </div>
        </form>
    );
}

// ─── System AI Tab ─────────────────────────────────────────────────────────────

function SystemAiTab({ systemAi, flash }) {
    const { t } = useTranslation();
    const { data, setData, put, processing, errors } = useForm({
        system_ai_enabled:       systemAi?.enabled ? 'true' : 'false',
        system_ai_base_url:      systemAi?.baseUrl ?? '',
        system_ai_default_model: systemAi?.defaultModel ?? '',
        system_ai_global_rules:  systemAi?.globalRules ?? '',
        system_ai_default_temperature: systemAi?.defaultTemperature ?? 0.3,
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.settings.system-ai.update'), { preserveScroll: true });
    };

    const field = (label, key, description, placeholder = '') => (
        <div className="space-y-1">
            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</label>
            {description && <p className="text-xs text-neutral-400 dark:text-neutral-500">{description}</p>}
            <input
                type="text"
                value={data[key]}
                onChange={(e) => setData(key, e.target.value)}
                placeholder={placeholder}
                className="w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/20"
            />
            {errors[key] && <p className="text-xs text-red-500">{errors[key]}</p>}
        </div>
    );

    return (
        <form onSubmit={submit} className="space-y-6">
            {flash?.success && (
                <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                    {flash.success}
                </div>
            )}

            <Card>
                <Card.Body className="space-y-5">
                    <div className="flex items-center gap-3 pb-4 border-b border-neutral-100 dark:border-neutral-800">
                        <Bot className="h-5 w-5 text-indigo-500" />
                        <div>
                            <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">System AI (Powered by Ollama)</h3>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                Configure the global Ollama AI provider that your clients can use via their plan's credits limit.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center justify-between rounded-soft border border-neutral-200 dark:border-neutral-700 px-4 py-3">
                        <div>
                            <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">Enable System AI</p>
                            <p className="text-xs text-neutral-400 dark:text-neutral-500 mt-0.5">Allow users to use the System AI instead of providing their own API keys.</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={data.system_ai_enabled === 'true'}
                            onClick={() => setData('system_ai_enabled', data.system_ai_enabled === 'true' ? 'false' : 'true')}
                            className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500/20 ${
                                data.system_ai_enabled === 'true' ? 'bg-brand-500' : 'bg-neutral-300 dark:bg-neutral-600'
                            }`}
                        >
                            <span
                                className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
                                    data.system_ai_enabled === 'true' ? 'translate-x-6' : 'translate-x-1'
                                }`}
                            />
                        </button>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        {field('Base URL', 'system_ai_base_url', 'Your Ollama Server Address', 'http://127.0.0.1:11434')}
                        {field('Default Model', 'system_ai_default_model', 'Model to use for client requests', 'qwen2:0.5b')}
                    </div>

                    <div className="space-y-2 rounded-soft border border-indigo-200 dark:border-indigo-800 bg-indigo-50/50 dark:bg-indigo-900/20 px-4 py-4">
                        <div>
                            <label className="block text-sm font-semibold text-neutral-900 dark:text-neutral-100">Global AI Rules</label>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-0.5">These rules are injected into EVERY AI chatbot's system prompt (after built-in guardrails, before the bot's own prompt). Use them for platform-wide policies — e.g. "Always mention COD is available", "Never quote prices above X".</p>
                        </div>
                        <textarea
                            value={data.system_ai_global_rules}
                            onChange={(e) => setData('system_ai_global_rules', e.target.value)}
                            rows={6}
                            placeholder={"- Always reply in the customer's language\n- COD available on all orders\n- Never quote delivery dates beyond 3 days"}
                            className="w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm font-mono resize-y focus:outline-none focus:ring-2 focus:ring-brand-500/20"
                        />
                        {errors['system_ai_global_rules'] && <p className="text-xs text-red-500">{errors['system_ai_global_rules']}</p>}
                        <div>
                            <div className="flex items-center justify-between">
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">Default Temperature for new chatbots</label>
                                <span className="text-xs font-mono text-neutral-500">{Number(data.system_ai_default_temperature).toFixed(1)}</span>
                            </div>
                            <input
                                type="range"
                                min={0}
                                max={1}
                                step={0.1}
                                value={data.system_ai_default_temperature}
                                onChange={(e) => setData('system_ai_default_temperature', Number(e.target.value))}
                                className="w-full accent-brand-600"
                            />
                            <p className="text-xs text-neutral-400 dark:text-neutral-500">Lower = consistent, factual replies (recommended 0.2–0.4 for support bots).</p>
                        </div>
                    </div>
                </Card.Body>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" variant="primary" disabled={processing}>
                    {processing ? t('settings.saving') : 'Save System AI Settings'}
                </Button>
            </div>
        </form>
    );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

// ─── Diagnostic report ────────────────────────────────────────────────

function DiagnosticReport({ report }) {
    const s = report.settings ?? {};
    const c = report.code ?? {};
    const probes = report.probes ?? [];

    return (
        <div className="rounded-soft border border-neutral-200 dark:border-neutral-700 divide-y divide-neutral-200 dark:divide-neutral-700 text-xs overflow-hidden">
            <div className="px-3 py-2 bg-neutral-50 dark:bg-neutral-800/60 space-y-1">
                <p className="font-semibold">Settings</p>
                <p>enabled: <b className={s.enabled ? 'text-green-600' : 'text-red-500'}>{s.enabled ? 'yes' : 'NO'}</b></p>
                <p>base_url: <span className="font-mono">{s.base_url || '(missing)'}</span></p>
                <p>api_key: {s.api_key_set ? <span className="font-mono">{s.api_key_preview}</span> : <b className="text-red-500">MISSING</b>}</p>
                <p>default_model: <span className="font-mono">{s.default_model}</span>{!s.model_is_recommended_combo && <b className="ml-1 text-amber-600">not an auto/* combo</b>}</p>
            </div>
            <div className="px-3 py-2 bg-neutral-50 dark:bg-neutral-800/60 space-y-1">
                <p className="font-semibold">Deployed code</p>
                <p>dead-upstream guards: <b className={c.defense_active ? 'text-green-600' : 'text-red-500'}>{c.defense_active ? 'ACTIVE' : 'MISSING — OLD CODE ON SERVER'}</b></p>
                {c.note && <p className="opacity-70">{c.note}</p>}
            </div>
            <div className="px-3 py-2 bg-neutral-50 dark:bg-neutral-800/60 space-y-1">
                <p className="font-semibold">Live gateway probes</p>
                {probes.length === 0 && <p className="opacity-70">(none ran)</p>}
                {probes.map((p, i) => (
                    <div key={i} className="rounded px-2 py-1.5 font-mono space-y-0.5 bg-white dark:bg-neutral-900 border border-neutral-200 dark:border-neutral-700">
                        <p><b className={p.ok ? 'text-green-600' : 'text-red-500'}>{p.ok ? 'OK' : 'FAIL'}</b> {p.model} <span className="opacity-60">({p.label})</span></p>
                        <p className="opacity-70">http {p.http_status} · {p.latency_ms}ms · upstream: {p.upstream_model ?? 'n/a'}</p>
                        {p.guard_reason && <p className="text-amber-600">guard: {p.guard_reason}</p>}
                        {p.reply_preview && <p>reply: “{p.reply_preview}”</p>}
                        {p.error && <p className="text-red-500">error: {p.error}</p>}
                        {!p.ok && p.raw_body && <p className="opacity-50 break-all">raw: {p.raw_body}</p>}
                    </div>
                ))}
            </div>
            <div className="px-3 py-2 bg-neutral-50 dark:bg-neutral-800/60 space-y-1">
                <p className="font-semibold">Workspace resolution (what bots actually use)</p>
                {report.workspace?.note ? (
                    <p className="opacity-70">{report.workspace.note}</p>
                ) : (
                    <>
                        <p>workspace_id: {report.workspace?.workspace_id_checked}</p>
                        <p>
                            resolved provider:{' '}
                            <span className="font-mono">{report.workspace?.resolved_provider?.class ?? 'error'}</span>
                            {report.workspace?.resolved_provider?.chat_model && (
                                <span className="font-mono"> · model: {report.workspace.resolved_provider.chat_model}</span>
                            )}
                        </p>
                        {report.workspace?.resolved_provider?.error && (
                            <p className="text-red-500">error: {report.workspace.resolved_provider.error}</p>
                        )}
                        {report.workspace?.workspace_provider_configs?.length > 0 && (
                            <p className="opacity-70">
                                workspace-level configs: {report.workspace.workspace_provider_configs.length} — these override the system gateway
                            </p>
                        )}
                    </>
                )}
            </div>
            <div className="px-3 py-2 bg-neutral-50 dark:bg-neutral-800/60 space-y-1">
                <p className="font-semibold">Knowledge base / RAG (why the bot ignores your KB)</p>
                {report.kb?.note ? (
                    <p className="opacity-70">{report.kb.note}</p>
                ) : (
                    <>
                        <p>
                            bot: {report.kb?.bot_checked?.id}:{report.kb?.bot_checked?.name} · KB attached:{' '}
                            {report.kb?.kb_attached ? (
                                <b className="text-green-600">yes</b>
                            ) : (
                                <b className="text-red-500">NO — bot has no KB</b>
                            )}
                        </p>
                        {report.kb?.kb_attached && (
                            <>
                                <p>
                                    kb: <span className="font-mono">{report.kb?.kb_name}</span> · status: {report.kb?.kb_status} · chunks:{' '}
                                    <b>{report.kb?.chunks_total ?? 0}</b> · with embedding: <b>{report.kb?.chunks_with_embedding ?? 0}</b>
                                    {report.kb?.chunks_with_embedding === 0 && (
                                        <b className="text-amber-600"> ← no vectors; keyword fallback only</b>
                                    )}
                                </p>
                                <p className="opacity-70">
                                    retrieval probe: {report.kb?.retrieval_probe?.chunks_found ?? 0} found /{' '}
                                    {report.kb?.retrieval_probe?.chunks_injected ?? 0} injected via{' '}
                                    <span className="font-mono">{report.kb?.retrieval_probe?.via ?? 'n/a'}</span>
                                    {report.kb?.retrieval_probe?.top_score != null && (
                                        <> · top_score: {report.kb.retrieval_probe.top_score}</>
                                    )}
                                </p>
                                {report.kb?.retrieval_probe?.preview && (
                                    <p className="opacity-50 break-all">preview: “{report.kb.retrieval_probe.preview}”</p>
                                )}
                                {report.kb?.retrieval_probe?.error && (
                                    <p className="text-red-500">retrieval error: {report.kb.retrieval_probe.error}</p>
                                )}
                            </>
                        )}
                    </>
                )}
            </div>
            <div className="px-3 py-2 bg-amber-50 dark:bg-amber-900/20">
                <p className="font-semibold">Diagnosis</p>
                <p className="mt-0.5">{report.diagnosis}</p>
            </div>
        </div>
    );
}

// ─── OmniRoute Gateway Tab ───────────────────────────────────────────────────

function OmniRouteTab({ omniroute, flash }) {
    const { data, setData, put, processing } = useForm({
        system_omniroute_enabled:       omniroute?.enabled ? 'true' : 'false',
        system_omniroute_base_url:      omniroute?.baseUrl ?? '',
        system_omniroute_api_key:       '',
        system_omniroute_default_model: omniroute?.defaultModel ?? '',
    });
    const [models, setModels] = useState(null);
    const [fetching, setFetching] = useState(false);
    const [fetchError, setFetchError] = useState('');
    const [testing, setTesting] = useState(false);
    const [testResult, setTestResult] = useState(null);

    // Curated combos that always resolve to a live upstream — safe defaults for chat.
    const RECOMMENDED_MODELS = ['auto/chat', 'auto/best-chat', 'auto/fast', 'auto/cheap', 'auto/reasoning', 'auto/smart'];

    const [diagnosing, setDiagnosing] = useState(false);
    const [diagResult, setDiagResult] = useState(null);

    const runDiagnostic = async () => {
        setDiagnosing(true); setDiagResult(null);
        try {
            const resp = await fetch(route('admin.settings.omniroute.diagnose'), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            setDiagResult(await resp.json());
        } catch {
            setDiagResult({ diagnosis: 'Diagnostic request failed — check network tab.' });
        } finally {
            setDiagnosing(false);
        }
    };

    const submit = (e) => {
        e.preventDefault();
        put(route('admin.settings.omniroute.update'), { preserveScroll: true });
    };

    const fetchModels = async () => {
        setFetching(true); setFetchError('');
        try {
            const resp = await fetch(route('admin.settings.omniroute.models'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    base_url: data.system_omniroute_base_url,
                    api_key: data.system_omniroute_api_key || undefined,
                }),
            });
            const json = await resp.json();
            if (!resp.ok) throw new Error(json.error || 'Request failed');
            setModels(json.models ?? []);
        } catch (err) {
            setModels(null);
            setFetchError(err.message);
        } finally {
            setFetching(false);
        }
    };

    const testModel = async (modelName) => {
        setTesting(true); setTestResult(null);
        try {
            const resp = await fetch(route('admin.settings.omniroute.test-model'), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    base_url: data.system_omniroute_base_url,
                    api_key: data.system_omniroute_api_key || undefined,
                    model: modelName || data.system_omniroute_default_model,
                }),
            });
            const json = await resp.json();
            setTestResult(json);
        } catch (err) {
            setTestResult({ ok: false, error: err.message });
        } finally {
            setTesting(false);
        }
    };

    const field = (label, key, description, placeholder = '', type = 'text') => (
        <div className="space-y-1">
            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</label>
            {description && <p className="text-xs text-neutral-400 dark:text-neutral-500">{description}</p>}
            <input
                type={type}
                value={data[key]}
                onChange={(e) => setData(key, e.target.value)}
                placeholder={placeholder}
                className="w-full rounded-soft border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 text-neutral-900 dark:text-neutral-100 px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-500/20"
            />
        </div>
    );

    return (
        <form onSubmit={submit} className="space-y-6">
            {flash?.success && (
                <div className="rounded-soft-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">
                    {flash.success}
                </div>
            )}
            <Card>
                <Card.Body className="space-y-5">
                    <div className="flex items-center gap-3 pb-4 border-b border-neutral-100 dark:border-neutral-800">
                        <Route className="h-5 w-5 text-violet-500" />
                        <div>
                            <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">OmniRoute Gateway</h3>
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                                OpenAI-compatible AI gateway (self-hosted, 175+ models). Serves all AI features when workspaces have no own provider.
                            </p>
                        </div>
                    </div>

                    <div className="flex items-center justify-between rounded-soft border border-neutral-200 dark:border-neutral-700 px-4 py-3">
                        <div>
                            <p className="text-sm font-medium text-neutral-700 dark:text-neutral-300">Enable OmniRoute</p>
                            <p className="text-xs text-neutral-400 dark:text-neutral-500 mt-0.5">Serve AI features through your self-hosted OmniRoute server.</p>
                        </div>
                        <button
                            type="button"
                            role="switch"
                            aria-checked={data.system_omniroute_enabled === 'true'}
                            onClick={() => setData('system_omniroute_enabled', data.system_omniroute_enabled === 'true' ? 'false' : 'true')}
                            className={`relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-brand-500/20 ${
                                data.system_omniroute_enabled === 'true' ? 'bg-brand-500' : 'bg-neutral-300 dark:bg-neutral-600'
                            }`}
                        >
                            <span
                                className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform ${
                                    data.system_omniroute_enabled === 'true' ? 'translate-x-6' : 'translate-x-1'
                                }`}
                            />
                        </button>
                    </div>

                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        {field('Base URL', 'system_omniroute_base_url', 'OpenAI-compatible endpoint (include /v1)', 'http://107.172.136.44:20128/v1')}
                        {field('API Key', 'system_omniroute_api_key', omniroute?.hasApiKey ? 'A key is saved — leave blank to keep it.' : 'Bearer token for the gateway', 'sk-…', 'password')}
                        {field('Default Model', 'system_omniroute_default_model', 'Model used when none is specified — pick a recommended combo below for reliable quality', 'auto/chat')}
                    </div>

                    <div className="space-y-2">
                        <p className="text-xs font-medium text-neutral-500 dark:text-neutral-400">Recommended for customer-support bots (auto-resolve to a live upstream model):</p>
                        <div className="flex flex-wrap gap-1.5">
                            {RECOMMENDED_MODELS.map((m) => (
                                <button
                                    key={m}
                                    type="button"
                                    onClick={() => setData('system_omniroute_default_model', m)}
                                    className={`rounded-full px-2.5 py-1 text-[11px] font-mono transition ${
                                        data.system_omniroute_default_model === m
                                            ? 'bg-brand-600 text-white'
                                            : 'bg-brand-50 dark:bg-brand-900/20 text-brand-700 dark:text-brand-300 border border-brand-200 dark:border-brand-800 hover:border-brand-400'
                                    }`}
                                >
                                    {m}
                                </button>
                            ))}
                        </div>
                        <p className="text-[11px] text-neutral-400 dark:text-neutral-500">
                            Avoid pinning concrete model ids (e.g. gpt-4o-mini) unless you verified them below — upstream credentials change and the model silently dies.
                        </p>
                    </div>

                    <div className="flex items-center gap-3">
                        <button
                            type="button"
                            onClick={fetchModels}
                            disabled={fetching || !data.system_omniroute_base_url}
                            className="rounded-soft border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-xs font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 disabled:opacity-50 transition"
                        >
                            {fetching ? 'Testing…' : 'Fetch Models / Test Connection'}
                        </button>
                        {models !== null && (
                            <span className="text-xs text-green-600 dark:text-green-400">
                                ✓ Connected — {models.length} models available
                            </span>
                        )}
                    </div>
                    {fetchError && <p className="text-xs text-red-500">{fetchError}</p>}

                    <div className="flex items-center gap-3 flex-wrap">
                        <button
                            type="button"
                            onClick={() => testModel()}
                            disabled={testing || !data.system_omniroute_default_model}
                            className="rounded-soft border border-brand-300 dark:border-brand-700 px-3 py-1.5 text-xs font-medium text-brand-700 dark:text-brand-300 hover:bg-brand-50 dark:hover:bg-brand-900/20 disabled:opacity-50 transition"
                        >
                            {testing ? 'Testing…' : `Test Model: ${data.system_omniroute_default_model || '—'}`}
                        </button>
                    </div>
                    {testResult && (
                        <div className={`rounded-soft px-3 py-2 text-xs font-mono ${
                            testResult.ok
                                ? 'bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200'
                                : 'bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-300'
                        }`}>
                            {testResult.ok ? (
                                <>
                                    ✓ Reply: “{testResult.reply}”
                                    {testResult.upstream_model && <span className="ml-2 opacity-70">via {testResult.upstream_model}</span>}
                                    <span className="ml-2 opacity-70">({testResult.latency_ms} ms)</span>
                                </>
                            ) : (
                                <>✗ {testResult.error || 'Empty reply — this model is not usable.'}</>
                            )}
                        </div>
                    )}

                    <div className="border-t border-neutral-200 dark:border-neutral-700 pt-4 space-y-2">
                        <div className="flex items-center gap-3">
                            <button
                                type="button"
                                onClick={runDiagnostic}
                                disabled={diagnosing}
                                className="rounded-soft bg-neutral-900 dark:bg-neutral-100 px-3 py-1.5 text-xs font-semibold text-white dark:text-neutral-900 hover:opacity-90 disabled:opacity-50 transition"
                            >
                                {diagnosing ? 'Running full diagnostic…' : '🔍 Run Full Diagnostic'}
                            </button>
                            <span className="text-[11px] text-neutral-400 dark:text-neutral-500">
                                Checks settings, deployed code, and live gateway — names the exact root cause.
                            </span>
                        </div>
                        {diagResult && <DiagnosticReport report={diagResult} />}
                    </div>
                    {models !== null && models.length > 0 && (
                        <div className="rounded-soft bg-neutral-50 dark:bg-neutral-800/60 px-3 py-2 flex flex-wrap gap-1.5">
                            {models.slice(0, 12).map((m) => (
                                <button
                                    key={m}
                                    type="button"
                                    onClick={() => setData('system_omniroute_default_model', m)}
                                    className={`rounded-full px-2 py-0.5 text-[11px] font-mono transition ${
                                        data.system_omniroute_default_model === m
                                            ? 'bg-brand-600 text-white'
                                            : 'bg-white dark:bg-neutral-700 text-neutral-600 dark:text-neutral-300 border border-neutral-200 dark:border-neutral-600 hover:border-brand-400'
                                    }`}
                                >
                                    {m}
                                </button>
                            ))}
                            {models.length > 12 && <span className="text-[11px] text-neutral-400 self-center">+{models.length - 12} more — click any model to set it as default</span>}
                        </div>
                    )}
                </Card.Body>
            </Card>
            <div className="flex justify-end">
                <Button type="submit" variant="primary" disabled={processing}>
                    {processing ? 'Saving…' : 'Save OmniRoute Settings'}
                </Button>
            </div>
        </form>
    );
}

export default function AdminSettingsIndex({ general = {}, settingsByGroup = {}, firebase = {}, systemAi = {}, omniroute = {} }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};

    const tabs = [
        { key: 'general',  label: t('settings.tab_general') },
        { key: 'firebase', label: t('settings.tab_firebase') },
        { key: 'systemAi', label: 'System AI' },
        { key: 'omniroute', label: 'OmniRoute' },
        { key: 'advanced', label: t('settings.tab_advanced') },
    ];

    const [activeIndex, setActiveIndex] = useState(0);

    return (
        <AdminLayout title={t('admin.system_settings')}>
            <Head title={`${t('admin.nav.settings')} · ${t('head.admin')}`} />
            <div className="space-y-6">
                <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('admin.system_settings')}</h2>

                <Tabs tabs={tabs} defaultIndex={0} onChange={(i) => setActiveIndex(i)}>
                    <Tabs.Panel index={0} activeIndex={activeIndex}>
                        <GeneralTab general={general} flash={flash} />
                    </Tabs.Panel>
                    <Tabs.Panel index={1} activeIndex={activeIndex}>
                        <FirebaseTab firebase={firebase} flash={flash} />
                    </Tabs.Panel>
                    <Tabs.Panel index={2} activeIndex={activeIndex}>
                        <SystemAiTab systemAi={systemAi} flash={flash} />
                    </Tabs.Panel>
                    <Tabs.Panel index={3} activeIndex={activeIndex}>
                        <OmniRouteTab omniroute={omniroute} flash={flash} />
                    </Tabs.Panel>
                    <Tabs.Panel index={4} activeIndex={activeIndex}>
                        <AdvancedTab settingsByGroup={settingsByGroup} flash={flash} />
                    </Tabs.Panel>
                </Tabs>
            </div>
        </AdminLayout>
    );
}
