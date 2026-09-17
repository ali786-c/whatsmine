import { ArrowLeft, Video, Phone, Camera, Mic, Heart } from 'lucide-react';

/**
 * Instagram template preview — renders a generic (carousel) or button
 * template inside a mock Instagram DM screen (dark theme, gradient
 * outgoing bubble, header + input bar), so the agent sees exactly what
 * the customer receives in their Instagram app.
 */

/** Outgoing template rendered in Instagram's own visual language */
export function IgTemplateDark({ payload }) {
    const isCarousel = payload?.template_type === 'generic';

    if (isCarousel) {
        // Carousel cards stand alone on the chat background (no bubble).
        return (
            <div className="flex max-w-full gap-2 overflow-x-auto pb-1">
                {(payload.elements ?? []).map((el, i) => (
                    <div key={i} className="w-40 shrink-0 overflow-hidden rounded-2xl bg-[#262626]">
                        {el.image_url
                            ? <img src={el.image_url} alt="" className="h-24 w-full object-cover" />
                            : <div className="h-16 w-full bg-gradient-to-br from-[#3a3a3a] to-[#262626]" />}
                        <div className="p-2">
                            <p className="text-xs font-semibold leading-tight text-white">{el.title}</p>
                            {el.subtitle && <p className="mt-0.5 line-clamp-2 text-[10px] leading-snug text-neutral-400">{el.subtitle}</p>}
                            {(el.buttons ?? []).map((b, j) => (
                                <div key={j} className="mt-1 rounded-full border border-white/25 px-2 py-1 text-center text-[10px] font-medium text-[#0095f6]">
                                    {b.title}
                                </div>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    // Button template: text in the gradient bubble, buttons inside it.
    return (
        <div className="max-w-[210px] rounded-2xl rounded-br-sm bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 p-2">
            <p className="whitespace-pre-wrap px-1 text-xs leading-snug text-white">{payload?.text}</p>
            {(payload.buttons ?? []).length > 0 && (
                <div className="mt-1.5 space-y-1">
                    {payload.buttons.map((b, j) => (
                        <div key={j} className="rounded-full border border-white/40 bg-white/10 px-2 py-1 text-center text-[10px] font-semibold text-white">
                            {b.title}
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}

/**
 * Full mock Instagram DM screen: header (back, avatar, name, call icons),
 * one sample incoming message, the template as the outgoing message, and
 * the "Message…" input bar.
 */
export function IgDmPreview({ payload, username = 'yourbusiness', incomingText = 'Hi! 👋' }) {
    return (
        <div className="flex w-full flex-col overflow-hidden rounded-2xl bg-black text-white ring-1 ring-neutral-200 dark:ring-neutral-800">
            {/* Header */}
            <div className="flex items-center gap-2 border-b border-white/10 px-3 py-2">
                <ArrowLeft className="h-4 w-4 shrink-0" />
                <div className="rounded-full bg-gradient-to-tr from-yellow-400 via-pink-500 to-purple-500 p-[2px]">
                    <div className="flex h-7 w-7 items-center justify-center rounded-full bg-neutral-900 text-[10px] font-bold">
                        {username.charAt(0).toUpperCase()}
                    </div>
                </div>
                <span className="flex-1 truncate text-xs font-semibold">{username}</span>
                <Video className="h-4 w-4 shrink-0" />
                <Phone className="h-4 w-4 shrink-0" />
            </div>

            {/* Messages */}
            <div className="flex-1 space-y-2 p-3" style={{ minHeight: 220 }}>
                {/* Sample incoming customer message */}
                <div className="flex items-end gap-1.5">
                    <div className="h-5 w-5 shrink-0 rounded-full bg-neutral-700" />
                    <div className="rounded-2xl rounded-bl-sm bg-[#262626] px-3 py-1.5 text-xs text-white">
                        {incomingText}
                    </div>
                </div>

                {/* The template as the outgoing business message */}
                <div className="flex justify-end pt-1">
                    <IgTemplateDark payload={payload} />
                </div>
            </div>

            {/* Input bar */}
            <div className="flex items-center gap-2 border-t border-white/10 px-3 py-2">
                <div className="flex h-6 w-6 items-center justify-center rounded-full border border-white/30">
                    <Camera className="h-3 w-3" />
                </div>
                <div className="flex-1 rounded-full border border-white/30 px-3 py-1 text-[11px] text-neutral-500">
                    Message…
                </div>
                <Mic className="h-4 w-4 shrink-0 text-neutral-400" />
                <Heart className="h-4 w-4 shrink-0 text-neutral-400" />
            </div>
        </div>
    );
}

/* ─── thread bubbles (real Inbox conversation, branded theme) ─── */

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

export default IgTemplateBody;
