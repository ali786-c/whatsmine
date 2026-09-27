import { describe, it, expect } from 'vitest';
import { splitHighlight, findMatchingMessageIds, partitionMedia, mediaLabel } from '@/Utils/inboxSearch';

describe('splitHighlight', () => {
    it('returns one plain segment for empty query', () => {
        expect(splitHighlight('Hello world', '')).toEqual([{ text: 'Hello world', match: false }]);
    });

    it('returns one plain segment for whitespace query', () => {
        expect(splitHighlight('Hello world', '   ')).toEqual([{ text: 'Hello world', match: false }]);
    });

    it('returns one plain segment for empty text', () => {
        expect(splitHighlight('', 'abc')).toEqual([{ text: '', match: false }]);
    });

    it('splits a single match into before/match/after', () => {
        expect(splitHighlight('big yellow bird', 'yellow')).toEqual([
            { text: 'big ', match: false },
            { text: 'yellow', match: true },
            { text: ' bird', match: false },
        ]);
    });

    it('matches case-insensitively but preserves original casing', () => {
        expect(splitHighlight('Yellow SUBmarine', 'yellow sub')).toEqual([
            { text: 'Yellow SUB', match: true },
            { text: 'marine', match: false },
        ]);
    });

    it('handles multiple matches and adjacent matches', () => {
        expect(splitHighlight('ababab', 'ab')).toEqual([
            { text: 'ab', match: true },
            { text: 'ab', match: true },
            { text: 'ab', match: true },
        ]);
        expect(splitHighlight('aaa', 'aa')).toEqual([
            { text: 'aa', match: true },
            { text: 'a', match: false },
        ]);
    });

    it('does not match when the needle is absent', () => {
        expect(splitHighlight('hello', 'xyz')).toEqual([{ text: 'hello', match: false }]);
    });

    it('treats regex metacharacters literally', () => {
        expect(splitHighlight('a.c aXc', 'a.c')).toEqual([
            { text: 'a.c', match: true },
            { text: ' aXc', match: false },
        ]);
    });

    it('handles null text input', () => {
        expect(splitHighlight(null, 'x')).toEqual([{ text: '', match: false }]);
    });
});

describe('findMatchingMessageIds', () => {
    const msgs = [
        { id: 1, body: 'Kya price hai?' },
        { id: 2, body: 'Sure, sending details', payload: { caption: null } },
        { id: 3, body: null, payload: { caption: 'Product PRICE list' } },
        { id: 4, body: 'nothing relevant here' },
    ];

    it('finds matches in body (case-insensitive), thread order', () => {
        expect(findMatchingMessageIds(msgs, 'PRICE')).toEqual([1, 3]);
    });

    it('matches media captions too', () => {
        expect(findMatchingMessageIds(msgs, 'product')).toEqual([3]);
    });

    it('returns empty for empty/whitespace query', () => {
        expect(findMatchingMessageIds(msgs, '')).toEqual([]);
        expect(findMatchingMessageIds(msgs, '  ')).toEqual([]);
    });

    it('returns empty for null message list', () => {
        expect(findMatchingMessageIds(null, 'x')).toEqual([]);
    });
});

describe('partitionMedia', () => {
    it('partitions images, videos, documents and audio', () => {
        const msgs = [
            { id: 1, type: 'text' },
            { id: 2, type: 'image' },
            { id: 3, type: 'video' },
            { id: 4, type: 'document' },
            { id: 5, type: 'audio' },
            { id: 6, type: 'sticker' },
            { id: 7, type: 'template' },
        ];
        const out = partitionMedia(msgs);
        expect(out.media.map(m => m.id)).toEqual([2, 3, 6]);
        expect(out.files.map(m => m.id)).toEqual([4]);
        expect(out.audio.map(m => m.id)).toEqual([5]);
    });

    it('ignores unknown and missing types', () => {
        const out = partitionMedia([{ id: 1, type: 'location' }, { id: 2 }, { id: 3, type: 'poll' }]);
        expect(out).toEqual({ media: [], files: [], audio: [] });
    });

    it('handles non-array input', () => {
        expect(partitionMedia(null)).toEqual({ media: [], files: [], audio: [] });
        expect(partitionMedia(undefined)).toEqual({ media: [], files: [], audio: [] });
    });
});

describe('mediaLabel', () => {
    it('prefers payload filename', () => {
        expect(mediaLabel({ payload: { filename: 'invoice.pdf' }, body: 'caption' })).toBe('invoice.pdf');
    });

    it('falls back to document filename then caption then body', () => {
        expect(mediaLabel({ payload: { document: { filename: 'doc.pdf' } } })).toBe('doc.pdf');
        expect(mediaLabel({ payload: { caption: 'a photo' }, body: 'different' })).toBe('a photo');
        expect(mediaLabel({ body: 'plain' })).toBe('plain');
    });

    it('ignores the (media) placeholder body', () => {
        expect(mediaLabel({ body: '(media)' })).toBe('');
    });

    it('returns empty string for null input', () => {
        expect(mediaLabel(null)).toBe('');
    });
});
