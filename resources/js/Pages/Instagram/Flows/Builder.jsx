import { Head, router, useForm } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { useCallback, useMemo, useState } from 'react';
import {
    ReactFlow, ReactFlowProvider, Background, Controls,
    useNodesState, useEdgesState, addEdge, Handle, Position, MarkerType,
} from '@xyflow/react';
import '@xyflow/react/dist/style.css';
import {
    MessageSquareText, Clock3, GitBranch, Flag, Zap,
    Save, Loader2, ArrowLeft, Play,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';
import Button from '@/Components/ui/Button';
import { IgFlowStepPreview } from '@/Components/Instagram/IgTemplatePreview';
import {
    NODE_TYPES, makeNode, starterGraph, hydrateGraph, serializeGraph, graphProblem,
} from '@/Utils/igFlow';

const TYPE_STYLE = {
    [NODE_TYPES.trigger]:   { color: '#7c3aed', bg: '#f5f3ff', Icon: Zap,  label: 'Comment' },
    [NODE_TYPES.send]:      { color: '#0ea5e9', bg: '#f0f9ff', Icon: MessageSquareText, label: 'Message' },
    [NODE_TYPES.wait]:      { color: '#f59e0b', bg: '#fffbeb', Icon: Clock3, label: 'Wait for reply' },
    [NODE_TYPES.condition]: { color: '#8b5cf6', bg: '#f5f3ff', Icon: GitBranch, label: 'Condition' },
    [NODE_TYPES.end]:       { color: '#64748b', bg: '#f8fafc', Icon: Flag, label: 'End' },
};

/* ─── Custom IG node card ─────────────────────────────────────────────── */

function IgFlowNode({ data, selected }) {
    const { t } = useTranslation();
    const type = data?.nodeType ?? NODE_TYPES.end;
    const style = TYPE_STYLE[type] ?? TYPE_STYLE[NODE_TYPES.end];
    const { Icon } = style;
    const isCondition = type === NODE_TYPES.condition;
    const isEnd = type === NODE_TYPES.end;

    const summary = useMemo(() => {
        const d = data ?? {};
        if (type === NODE_TYPES.send) {
            if (d.kind === 'template') return d.template_name ? `📄 ${d.template_name}` : '📄 Template';
            const txt = (d.text ?? '').trim();
            return txt ? txt.slice(0, 42) : t('instagram_flows.node_unconfigured');
        }
        if (type === NODE_TYPES.condition) {
            return d.cond_type === 'keyword' ? `🔑 ${(d.keywords ?? []).join(', ').slice(0, 40)}` : '✓ Followed?';
        }
        if (type === NODE_TYPES.wait) return `⏱ ${d.timeout_minutes ?? 60} min`;
        if (type === NODE_TYPES.trigger) return '💬 New comment';
        return '';
    }, [data, type, t]);

    return (
        <div
            style={{
                background: '#fff',
                border: `1.5px solid ${selected ? style.color : '#e5e7eb'}`,
                borderRadius: 12,
                minWidth: 190,
                maxWidth: 230,
                boxShadow: selected ? `0 0 0 3px ${style.color}22, 0 8px 20px rgba(0,0,0,0.08)` : '0 1px 3px rgba(0,0,0,0.06)',
                overflow: 'hidden',
            }}
        >
            {!isEnd && <Handle type="target" position={Position.Top} style={{ background: '#fff', width: 9, height: 9, border: `2px solid ${style.color}` }} />}

            <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 10px' }}>
                <span style={{ display: 'flex', alignItems: 'center', justifyContent: 'center', width: 28, height: 28, borderRadius: 8, flexShrink: 0, background: style.bg, color: style.color }}>
                    <Icon size={15} />
                </span>
                <div style={{ minWidth: 0, flex: 1 }}>
                    <div style={{ fontSize: 12, fontWeight: 600, color: '#111827' }}>{style.label}</div>
                    <div style={{ fontSize: 10.5, color: '#6b7280', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' }}>
                        {summary || <span style={{ fontStyle: 'italic', color: '#9ca3af' }}>{t('instagram_flows.click_to_configure')}</span>}
                    </div>
                </div>
            </div>

            {isCondition ? (
                <>
                    <div style={{ display: 'flex', justifyContent: 'space-between', padding: '0 14px 6px', fontSize: 9, fontWeight: 700 }}>
                        <span style={{ color: '#10b981' }}>✓ YES</span>
                        <span style={{ color: '#ef4444' }}>✗ NO</span>
                    </div>
                    <div style={{ position: 'relative', height: 10 }}>
                        <Handle type="source" id="yes" position={Position.Bottom} style={{ left: '28%', background: '#fff', width: 10, height: 10, border: '2px solid #10b981' }} />
                        <Handle type="source" id="no" position={Position.Bottom} style={{ left: '72%', background: '#fff', width: 10, height: 10, border: '2px solid #ef4444' }} />
                    </div>
                </>
            ) : (
                !isEnd && <Handle type="source" id="next" position={Position.Bottom} style={{ background: '#fff', width: 9, height: 9, border: `2px solid ${style.color}` }} />
            )}
        </div>
    );
}

const nodeTypes = { ig: IgFlowNode };

/* ─── Inspector (right panel) ─────────────────────────────────────────── */

function Inspector({ node, update, remove, templates }) {
    const { t } = useTranslation();

    if (!node) {
        return (
            <div className="flex h-full flex-col items-center justify-center gap-2 p-6 text-center">
                <p className="text-xs text-neutral-400">{t('instagram_flows.select_node_hint')}</p>
            </div>
        );
    }

    const d = { ...(node.data ?? {}) };
    const type = d.nodeType;
    const set = (patch) => update(node.id, patch);

    return (
        <div className="space-y-4 p-4">
            <div className="flex items-center justify-between">
                <h3 className="text-sm font-bold text-neutral-800 dark:text-neutral-100">
                    {TYPE_STYLE[type]?.label ?? 'Node'}
                </h3>
                {type !== NODE_TYPES.trigger && type !== NODE_TYPES.end && (
                    <button onClick={() => remove(node.id)} className="text-xs text-red-500 hover:underline">
                        {t('common.delete')}
                    </button>
                )}
            </div>

            {type === NODE_TYPES.send && (
                <div className="space-y-3">
                    <div className="flex rounded-lg bg-neutral-100 dark:bg-neutral-800 p-0.5 text-xs font-semibold">
                        {['text', 'template'].map((k) => (
                            <button key={k} onClick={() => set({ kind: k })}
                                className={`flex-1 rounded-md py-1.5 transition ${ (d.kind ?? 'text') === k ? 'bg-white dark:bg-neutral-700 shadow text-brand-600' : 'text-neutral-500'}`}>
                                {k === 'text' ? t('instagram_flows.text_msg') : t('instagram_flows.template_msg')}
                            </button>
                        ))}
                    </div>

                    {(d.kind ?? 'text') === 'text' ? (
                        <textarea
                            value={d.text ?? ''}
                            onChange={(e) => set({ text: e.target.value })}
                            rows={4}
                            maxLength={640}
                            placeholder={t('instagram_flows.text_placeholder')}
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                        />
                    ) : (
                        <select
                            value={d.template_id ?? ''}
                            onChange={(e) => {
                                const tpl = templates.find((x) => String(x.id) === e.target.value);
                                set({ template_id: tpl?.id ?? null, template_name: tpl?.name ?? null, definition: tpl?.definition ?? null });
                            }}
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                        >
                            <option value="">{t('instagram_flows.pick_template')}</option>
                            {templates.map((tpl) => (
                                <option key={tpl.id} value={tpl.id}>{tpl.name} ({tpl.type})</option>
                            ))}
                        </select>
                    )}
                </div>
            )}

            {type === NODE_TYPES.wait && (
                <label className="block text-xs font-medium text-neutral-600 dark:text-neutral-300">
                    {t('instagram_flows.timeout_minutes')}
                    <input type="number" min={1} max={1440} value={d.timeout_minutes ?? 60}
                        onChange={(e) => set({ timeout_minutes: Math.max(1, Number(e.target.value) || 1) })}
                        className="mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm" />
                </label>
            )}

            {type === NODE_TYPES.condition && (
                <div className="space-y-3">
                    <div className="flex rounded-lg bg-neutral-100 dark:bg-neutral-800 p-0.5 text-xs font-semibold">
                        {[['follow_check', t('instagram_flows.cond_follow')], ['keyword', t('instagram_flows.cond_keyword')]].map(([k, label]) => (
                            <button key={k} onClick={() => set({ cond_type: k })}
                                className={`flex-1 rounded-md py-1.5 transition ${(d.cond_type ?? 'follow_check') === k ? 'bg-white dark:bg-neutral-700 shadow text-brand-600' : 'text-neutral-500'}`}>
                                {label}
                            </button>
                        ))}
                    </div>

                    {(d.cond_type ?? 'follow_check') === 'keyword' && (
                        <div>
                            <p className="mb-1 text-[10px] font-bold uppercase tracking-wider text-neutral-400">{t('instagram_flows.keywords')}</p>
                            <input
                                type="text"
                                value={(d.keywords ?? []).join(', ')}
                                onChange={(e) => set({ keywords: e.target.value.split(',').map((s) => s.trim()).filter(Boolean) })}
                                placeholder={t('instagram_flows.keywords_placeholder')}
                                className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm"
                            />
                        </div>
                    )}

                    <p className="rounded-lg bg-neutral-50 dark:bg-neutral-800 p-2 text-[11px] leading-relaxed text-neutral-500">
                        {d.cond_type === 'keyword'
                            ? t('instagram_flows.cond_keyword_hint')
                            : t('instagram_flows.cond_follow_hint')}
                    </p>
                </div>
            )}

            {type === NODE_TYPES.trigger && (
                <p className="rounded-lg bg-violet-50 dark:bg-violet-900/30 p-2 text-[11px] text-violet-600 dark:text-violet-300">
                    {t('instagram_flows.trigger_hint')}
                </p>
            )}

            {type === NODE_TYPES.end && (
                <p className="rounded-lg bg-neutral-50 dark:bg-neutral-800 p-2 text-[11px] text-neutral-500">
                    {t('instagram_flows.end_hint')}
                </p>
            )}
        </div>
    );
}

/* ─── Builder shell ───────────────────────────────────────────────────── */

function BuilderInner({ flow, templates, accounts }) {
    const { t } = useTranslation();
    const initial = flow?.graph ? hydrateGraph(flow.graph) : starterGraph();
    const [nodes, setNodes, onNodesChange] = useNodesState(initial.nodes);
    const [edges, setEdges, onEdgesChange] = useEdgesState(initial.edges);
    const [selectedId, setSelectedId] = useState(null);

    const form = useForm({
        name: flow?.name ?? '',
        status: flow?.status ?? 'draft',
        instagram_account_id: null,
        graph: serializeGraph(initial),
    });

    const onConnect = useCallback((params) => {
        setEdges((eds) => addEdge({ ...params, markerEnd: { type: MarkerType.ArrowClosed } }, eds));
    }, [setEdges]);

    const addNode = (type) => {
        setNodes((ns) => {
            const maxX = ns.reduce((m, n) => Math.max(m, n.position?.x ?? 0), 0);
            const n = makeNode(type, { x: maxX + 60, y: 120 + ((ns.length * 67) % 220) });
            return [...ns, n];
        });
    };

    const updateNodeData = (id, patch) => {
        setNodes((ns) => ns.map((n) => (n.id === id ? { ...n, data: { ...n.data, ...patch } } : n)));
    };

    const syncGraph = () => form.setData('graph', serializeGraph({ nodes, edges }));

    const removeNode = (id) => {
        setNodes((ns) => ns.filter((n) => n.id !== id));
        setEdges((es) => es.filter((e) => e.source !== id && e.target !== id));
        if (selectedId === id) setSelectedId(null);
    };

    const selected = nodes.find((n) => n.id === selectedId) ?? null;

    const selectNode = (node) => {
        setSelectedId(node?.id ?? null);
    };

    const save = (activateAfter = false) => {
        const graph = serializeGraph({ nodes, edges });
        const problem = graphProblem(graph);

        if (problem) {
            window.alert(problem);
            return;
        }

        const payload = {
            name: form.data.name,
            status: activateAfter ? 'active' : (form.data.status ?? 'draft'),
            graph,
        };

        const url = flow ? route('client.instagram.flows.update', flow.id) : route('client.instagram.flows.store');
        const method = flow ? 'put' : 'post';

        form.submit(method, url, {
            data: payload,
            preserveScroll: true,
            onSuccess: () => {
                if (!flow) router.visit(route('client.instagram.flows.index'));
            },
        });
    };

    return (
        <div className="flex h-[calc(100vh-64px)] flex-col">
            {/* Top bar */}
            <div className="flex flex-wrap items-center gap-3 border-b border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-4 py-2.5">
                <a href={route('client.instagram.flows.index')} className="text-neutral-400 hover:text-neutral-600"><ArrowLeft className="h-4 w-4" /></a>
                <input
                    value={form.data.name}
                    onChange={(e) => form.setData('name', e.target.value)}
                    placeholder={t('instagram_flows.name_placeholder')}
                    className="min-w-[180px] flex-1 rounded-lg border border-transparent bg-transparent px-2 py-1 text-sm font-bold hover:border-neutral-300 focus:border-brand-500 focus:outline-none dark:text-white dark:hover:border-neutral-600"
                />
                <div className="flex items-center gap-1.5">
                    {Object.entries({ trigger: NODE_TYPES.trigger, send: NODE_TYPES.send, wait: NODE_TYPES.wait, condition: NODE_TYPES.condition, end: NODE_TYPES.end }).map(([key, type]) => (
                        <button key={key} onClick={() => addNode(type)}
                            className="rounded-lg border border-neutral-200 dark:border-neutral-700 px-2 py-1 text-[11px] font-semibold text-neutral-600 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800">
                            + {TYPE_STYLE[type].label}
                        </button>
                    ))}
                </div>
                <div className="ml-auto flex items-center gap-2">
                    <Button variant="outline" size="sm" onClick={() => { syncGraph(); save(false); }} disabled={form.processing}>
                        {form.processing ? <Loader2 className="h-4 w-4 animate-spin" /> : <Save className="h-4 w-4" />}
                        {t('common.save')}
                    </Button>
                    <Button size="sm" onClick={() => { syncGraph(); save(true); }} disabled={form.processing}>
                        <Play className="h-4 w-4" />
                        {t('instagram_flows.activate')}
                    </Button>
                </div>
            </div>

            <div className="flex flex-1 overflow-hidden">
                {/* Canvas */}
                <div className="relative flex-1">
                    <ReactFlow
                        nodes={nodes}
                        edges={edges.map((e) => ({
                            ...e,
                            animated: true,
                            style: e.sourceHandle === 'yes' ? { stroke: '#10b981', strokeWidth: 2 }
                                : e.sourceHandle === 'no' ? { stroke: '#ef4444', strokeWidth: 2 }
                                : e.sourceHandle === 'match' ? { stroke: '#10b981', strokeWidth: 2 }
                                : e.sourceHandle === 'default' ? { stroke: '#f59e0b', strokeWidth: 2 } : undefined,
                            label: e.sourceHandle === 'yes' || e.sourceHandle === 'match' ? 'YES'
                                : e.sourceHandle === 'no' ? 'NO'
                                : e.sourceHandle === 'default' ? 'ELSE' : undefined,
                        }))}
                        onNodesChange={(changes) => { onNodesChange(changes); }}
                        onEdgesChange={onEdgesChange}
                        onConnect={onConnect}
                        nodeTypes={nodeTypes}
                        onNodeClick={(_, node) => selectNode(node)}
                        onNodeDragStop={(_, node) => selectNode(node)}
                        fitView
                        proOptions={{ hideAttribution: true }}
                    >
                        <Background gap={18} />
                        <Controls showInteractive={false} />
                    </ReactFlow>
                </div>

                {/* Right panel: inspector + live IG preview */}
                <div className="w-[300px] shrink-0 overflow-y-auto border-l border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900">
                    <Inspector node={selected} templates={templates}
                        update={updateNodeData} remove={removeNode} />

                    {selected?.data?.nodeType === NODE_TYPES.send && (
                        <div className="border-t border-neutral-200 dark:border-neutral-700 p-3">
                            <p className="mb-2 text-[10px] font-bold uppercase tracking-wider text-neutral-400">{t('instagram_flows.live_preview')}</p>
                            <IgFlowStepPreview step={selected.data} username={accounts?.[0]?.username ?? 'yourbusiness'} />
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}

export default function IgFlowBuilder({ flow, templates, accounts }) {
    return (
        <ClientLayout>
            <Head title="DM Flow Builder" />
            <ReactFlowProvider>
                <BuilderInner flow={flow} templates={templates} accounts={accounts} />
            </ReactFlowProvider>
        </ClientLayout>
    );
}
