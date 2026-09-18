/**
 * Instagram DM Flow builder helpers — shared between the Builder page and the
 * flow node inspector. Mirrors the server-side node contract in
 * FlowEngine.php + FlowController::validateGraph().
 */

export const NODE_TYPES = {
    trigger: 'trigger',
    send: 'send_message',
    wait: 'wait_reply',
    condition: 'condition',
    end: 'end',
};

/** @returns {object} fresh node data payload for a type */
export function nodeData(type) {
    switch (type) {
        case NODE_TYPES.trigger:
            return { trigger: 'comment' };
        case NODE_TYPES.send:
            return { kind: 'text', text: '' };
        case NODE_TYPES.wait:
            return { timeout_minutes: 60 };
        case NODE_TYPES.condition:
            return { cond_type: 'follow_check', keywords: [] };
        case NODE_TYPES.end:
        default:
            return {};
    }
}

/** @returns {object} ReactFlow-ready node */
export function makeNode(type, position) {
    return {
        id: `${type}-${Math.random().toString(36).slice(2, 9)}`,
        type: 'ig',
        position,
        data: { nodeType: type, ...nodeData(type) },
    };
}

/** Start graph: Trigger → Send → End */
export function starterGraph() {
    const trigger = makeNode(NODE_TYPES.trigger, { x: 80, y: 160 });
    const send = makeNode(NODE_TYPES.send, { x: 340, y: 160 });
    const end = makeNode(NODE_TYPES.end, { x: 620, y: 160 });

    return {
        nodes: [trigger, send, end],
        edges: [
            { id: `e-${trigger.id}-${send.id}`, source: trigger.id, target: send.id, sourceHandle: 'next' },
            { id: `e-${send.id}-${end.id}`, source: send.id, target: end.id, sourceHandle: 'next' },
        ],
    };
}

/** Basic client-side sanity before save — server re-validates everything. */
export function graphProblem(graph) {
    const nodes = (graph?.nodes ?? []).filter(Boolean);
    const ids = new Set(nodes.map((n) => n.id));
    const triggers = nodes.filter((n) => n.data?.nodeType === NODE_TYPES.trigger);

    if (triggers.length !== 1) return 'A flow needs exactly one Trigger node.';

    for (const n of nodes) {
        const d = n.data ?? {};

        if (d.nodeType === NODE_TYPES.send) {
            if (d.kind === 'template') {
                if (!(Number(d.template_id) > 0) && !d.definition) return 'A template message node needs a template.';
            } else if (!(d.text ?? '').trim()) {
                return 'Every message node needs text.';
            }
        }

        if (d.nodeType === NODE_TYPES.condition && d.cond_type === 'keyword') {
            if ((d.keywords ?? []).map((k) => (k ?? '').trim()).filter(Boolean).length === 0) {
                return 'Keyword conditions need at least one keyword.';
            }
        }
    }

    for (const e of graph?.edges ?? []) {
        if (!ids.has(e.source) || !ids.has(e.target)) return 'Reconnect highlighted edges — a node was deleted.';
    }

    return null;
}

/** Strip React-only fields before POSTing to the server. */
export function serializeGraph(graph) {
    return {
        nodes: (graph?.nodes ?? []).filter(Boolean).map((n) => ({
            id: n.id,
            type: n.data?.nodeType ?? 'end',
            data: n.data ?? {},
            position: { x: Math.round(n.position?.x ?? 0), y: Math.round(n.position?.y ?? 0) },
        })),
        edges: (graph?.edges ?? []).filter(Boolean).map((e) => ({
            id: e.id,
            source: e.source,
            target: e.target,
            sourceHandle: e.sourceHandle ?? 'next',
        })),
    };
}

/** Hydrate a stored server graph back into ReactFlow nodes. */
export function hydrateGraph(graph) {
    return {
        nodes: (graph?.nodes ?? []).map((n) => ({
            id: String(n.id),
            type: 'ig',
            position: n.position ?? { x: 0, y: 0 },
            data: { nodeType: n.type ?? 'end', ...(n.data ?? {}) },
        })),
        edges: (graph?.edges ?? []).map((e) => ({
            id: String(e.id ?? `${e.source}-${e.target}`),
            source: String(e.source),
            target: String(e.target),
            sourceHandle: e.sourceHandle ?? 'next',
        })),
    };
}
