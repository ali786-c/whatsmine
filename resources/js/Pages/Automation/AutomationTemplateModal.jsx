import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ShoppingCart, ShoppingBag, Sparkles, Plus, Loader2 } from 'lucide-react';

export default function AutomationTemplateModal({ templates = {}, onClose, onSelect, processing }) {
    const { t } = useTranslation();

    // Helper to get an icon based on trigger type
    const getIcon = (triggerType) => {
        if (triggerType === 'cart.abandoned') return <ShoppingCart className="h-6 w-6" />;
        if (triggerType === 'order.placed') return <ShoppingBag className="h-6 w-6" />;
        return <Sparkles className="h-6 w-6" />;
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div className="w-full max-w-2xl rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4 max-h-[90vh] flex flex-col">
                <div className="flex items-center justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('automation.templates_title') || 'Pre-built Templates Gallery'}
                        </h3>
                        <p className="text-sm text-neutral-500">
                            {t('automation.templates_subtitle') || 'Start quickly with our pre-built visual automation workflows.'}
                        </p>
                    </div>
                    <button onClick={onClose} disabled={processing} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300">
                        <svg className="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div className="overflow-y-auto pr-2 grid grid-cols-1 sm:grid-cols-2 gap-4 pb-4">
                    {Object.entries(templates).map(([key, template]) => (
                        <div
                            key={key}
                            className="group flex flex-col rounded-xl border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 p-5 transition hover:border-brand-500 hover:shadow-md dark:hover:border-brand-500 cursor-pointer"
                            onClick={() => !processing && onSelect(key)}
                        >
                            <div className="flex items-center gap-3">
                                <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white dark:bg-neutral-800 text-brand-600 dark:text-brand-400 shadow-sm border border-neutral-100 dark:border-neutral-700">
                                    {getIcon(template.trigger_type)}
                                </span>
                                <div>
                                    <h4 className="font-semibold text-neutral-900 dark:text-neutral-100 group-hover:text-brand-600 transition">
                                        {template.name}
                                    </h4>
                                    <span className="text-[11px] uppercase tracking-wide text-neutral-400 font-medium mt-0.5 block">
                                        {template.trigger_type}
                                    </span>
                                </div>
                            </div>
                            <p className="mt-3 text-sm text-neutral-600 dark:text-neutral-400 line-clamp-3">
                                {template.description}
                            </p>
                            <div className="mt-auto pt-4 flex items-center text-sm font-medium text-brand-600 dark:text-brand-400 group-hover:underline">
                                {processing ? <Loader2 className="h-4 w-4 animate-spin mr-1.5" /> : <Plus className="h-4 w-4 mr-1.5" />}
                                {t('automation.use_template') || 'Use this template'}
                            </div>
                        </div>
                    ))}
                    
                    {Object.keys(templates).length === 0 && (
                        <div className="col-span-2 text-center py-8 text-neutral-500 text-sm">
                            No templates available.
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
