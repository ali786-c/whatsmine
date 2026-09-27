/**
 * Shared inbox thread utilities — used by the Inbox conversation view
 * (resources/js/Pages/Inbox/Show.jsx).
 *
 * Two concerns live here so they can be unit-tested without rendering React:
 *  1. In-chat keyword search: splitting a message body into highlighted /
 *     plain segments and locating matching message ids.
 *  2. Shared-Media tab: partitioning messages into media / files / audio.
 */

/**
 * Split `text` into segments for search highlighting.
 *
 * Returns an array of { text, match } pieces in order; every occurrence of
 * `query` (case-insensitive, plain substring — not regex) gets `match: true`
 * so the UI can wrap it in a highlight span. An empty/whitespace query or
 * empty text returns the whole text as one unhighlighted segment, so callers
 * can render unconditionally.
 *
 * @param {string} text
 * @param {string} query
 * @returns {Array<{ text: string, match: boolean }>}
 */
export function splitHighlight(text, query) {
    const value = String(text ?? '');
    const needle = String(query ?? '').trim().toLowerCase();

    if (!needle || !value) {
        return [{ text: value, match: false }];
    }

    const segments = [];
    let cursor = 0;
    for (;;) {
        const hit = value.toLowerCase().indexOf(needle, cursor);
        if (hit === -1) {
            break;
        }
        if (hit > cursor) {
            segments.push({ text: value.slice(cursor, hit), match: false });
        }
        segments.push({ text: value.slice(hit, hit + needle.length), match: true });
        cursor = hit + needle.length;
    }
    if (cursor < value.length) {
        segments.push({ text: value.slice(cursor), match: false });
    }

    return segments.length > 0 ? segments : [{ text: value, match: false }];
}

/**
 * Ids of messages whose body contains the query (case-insensitive), in thread
 * order. Media captions are searched alongside the body.
 *
 * @param {Array<{ id: number|string, body?: string|null, payload?: object|null }>} messages
 * @param {string} query
 * @returns {Array<number|string>}
 */
export function findMatchingMessageIds(messages, query) {
    const needle = String(query ?? '').trim().toLowerCase();
    if (!needle || !Array.isArray(messages)) {
        return [];
    }

    return (messages ?? [])
        .filter((m) => {
            const haystacks = [m?.body, m?.payload?.caption];
            return haystacks.some((h) => typeof h === 'string' && h.toLowerCase().includes(needle));
        })
        .map((m) => m.id);
}

/**
 * Partition thread messages for the Shared Media tab.
 *
 *  - media:  images + videos (+ stickers — they render like images)
 *  - files:  documents
 *  - audio:  voice notes / audio clips
 *
 * Everything else (text, templates, locations, unsupported…) is ignored.
 *
 * @param {Array<{ id: number|string, type: string }>} messages
 * @returns {{ media: Array, files: Array, audio: Array }}
 */
export function partitionMedia(messages) {
    const out = { media: [], files: [], audio: [] };
    if (!Array.isArray(messages)) {
        return out;
    }

    for (const m of messages) {
        switch (m?.type) {
            case 'image':
            case 'sticker':
            case 'video':
                out.media.push(m);
                break;
            case 'document':
                out.files.push(m);
                break;
            case 'audio':
                out.audio.push(m);
                break;
            default:
                break;
        }
    }

    return out;
}

/**
 * Best human label for a media message — filename for documents, caption or
 * a type word otherwise.
 *
 * @param {{ body?: string|null, payload?: object|null }} msg
 * @returns {string}
 */
export function mediaLabel(msg) {
    const p = msg?.payload ?? {};
    return p.filename
        ?? p.document?.filename
        ?? p.caption
        ?? (typeof msg?.body === 'string' && msg.body && msg.body !== '(media)' ? msg.body : '');
}
