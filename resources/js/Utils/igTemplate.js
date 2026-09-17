/**
 * Shared Instagram template helpers — used by both the Inbox composer
 * (resources/js/Pages/Inbox/Show.jsx) and the Instagram Templates gallery
 * pages (resources/js/Pages/Instagram/Templates/*).
 *
 * Meta limits (Generic/Button Template docs): 10 elements max,
 * title/subtitle 80 chars, 3 buttons per element, button text 640 chars,
 * button titles 20 chars.
 */

export const emptyIgButton = () => ({ type: 'web_url', title: '', url: '', payload: '' });

export const emptyIgCard = () => ({ title: '', subtitle: '', image_url: '', buttons: [] });

export const emptyIgDefinition = (type) => (type === 'button'
    ? { text: '', buttons: [emptyIgButton()] }
    : { elements: [emptyIgCard()] });

/** Build the ready-to-send Graph `message` attachment object from a definition */
export function buildIgTemplateMessage(type, def) {
    if (type === 'button') {
        return {
            attachment: {
                type: 'template',
                payload: {
                    template_type: 'button',
                    text: (def.text || '').slice(0, 640),
                    buttons: (def.buttons || []).map(b => b.type === 'web_url'
                        ? { type: 'web_url', title: (b.title || '').slice(0, 20), url: b.url }
                        : { type: 'postback', title: (b.title || '').slice(0, 20), payload: b.payload }),
                },
            },
        };
    }

    return {
        attachment: {
            type: 'template',
            payload: {
                template_type: 'generic',
                elements: (def.elements || []).slice(0, 10).map(el => ({
                    title: (el.title || '').slice(0, 80),
                    ...(el.subtitle ? { subtitle: el.subtitle.slice(0, 80) } : {}),
                    ...(el.image_url ? { image_url: el.image_url } : {}),
                    ...(el.buttons?.length ? {
                        buttons: el.buttons.slice(0, 3).map(b => b.type === 'web_url'
                            ? { type: 'web_url', title: (b.title || '').slice(0, 20), url: b.url }
                            : { type: 'postback', title: (b.title || '').slice(0, 20), payload: b.payload }),
                    } : {}),
                })),
            },
        },
    };
}

/** Does the definition form a sendable template? */
export function igTemplateValid(type, def) {
    const btnOk = (b) => (b?.title || '').trim() !== ''
        && ((b?.type ?? 'web_url') === 'web_url' ? (b?.url || '').trim() !== '' : (b?.payload || '').trim() !== '');

    if (type === 'button') {
        return (def?.text || '').trim() !== ''
            && (def?.buttons || []).filter(Boolean).length > 0
            && def.buttons.filter(Boolean).every(btnOk);
    }

    return (def?.elements || []).length > 0
        && def.elements.every(el => (el?.title || '').trim() !== '' && (el?.buttons || []).every(btnOk));
}

/** A short one-line summary of a definition (for list rows) */
export function igTemplateSummary(type, def) {
    if (type === 'button') {
        const btns = (def?.buttons || []).length;
        return `${(def?.text || '').slice(0, 60)}${(def?.text || '').length > 60 ? '…' : ''} · ${btns} button${btns === 1 ? '' : 's'}`;
    }

    const cards = (def?.elements || []).length;
    return `${cards} card${cards === 1 ? '' : 's'}`;
}
