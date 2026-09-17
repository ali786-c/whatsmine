/**
 * Instagram template preview — renders a generic (carousel) or button
 * template definition exactly like the bubbles in the Inbox thread, so
 * what the agent sees while composing matches what the customer receives.
 */
export function IgTemplateBody({ payload, isOut = false }) {
    const isCarousel = payload?.template_type === 'generic';

    if (isCarousel) {
        return (
            <div className="flex gap-2 overflow-x-auto p-2 snap-x">
                {(payload.elements ?? []).map((el, i) => (
                    <div key={i} className="w-44 shrink-0 rounded-xl border border-neutral-200 dark:border-neutral-600 overflow-hidden snap-start bg-white dark:bg-neutral-900">
                        {el.image_url && <img src={el.image_url} alt="" className="w-full h-24 object-cover" />}
                        <div className="p-2">
                            <p className="text-xs font-semibold truncate">{el.title}</p>
                            {el.subtitle && <p className="text-[11px] text-neutral-500 mt-0.5 line-clamp-2">{el.subtitle}</p>}
                            {(el.buttons ?? []).length > 0 && (
                                <div className="mt-1.5 space-y-1">
                                    {el.buttons.map((b, j) => (
                                        <div key={j} className="text-[10px] text-brand-600 dark:text-brand-400 border border-current/20 rounded px-1.5 py-0.5 truncate">
                                            {b.type === 'web_url' ? '🔗' : '↩'} {b.title}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    return (
        <div className="p-1">
            <p className="text-xs whitespace-pre-wrap px-1.5">{payload?.text}</p>
            {(payload.buttons ?? []).length > 0 && (
                <div className="mt-2 space-y-1">
                    {payload.buttons.map((b, j) => (
                        <div key={j} className={`text-[11px] rounded-lg px-2 py-1.5 truncate ${
                            isOut ? 'bg-white/15 border border-white/25' : 'bg-neutral-50 dark:bg-neutral-700 border border-neutral-200 dark:border-neutral-600'
                        }`}>
                            {b.type === 'web_url' ? '🔗' : '↩'} {b.title}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/** Green phone-bubble wrapper used in the editors' live preview panel */
export function IgTemplatePhoneFrame({ children }) {
    return (
        <div className="rounded-2xl bg-neutral-100 dark:bg-neutral-800 p-3">
            <div className="mx-auto max-w-[240px] rounded-2xl bg-brand-600 text-white shadow-md overflow-hidden">
                {children}
            </div>
            <p className="mt-2 text-center text-[10px] text-neutral-400">Preview — exactly as the customer sees it</p>
        </div>
    );
}

export default IgTemplateBody;
