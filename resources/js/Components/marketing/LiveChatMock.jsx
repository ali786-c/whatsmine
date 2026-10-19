import Reveal from '@/Components/motion/Reveal';

/**
 * "One Inbox, Every Channel" live chat mockup — the reference's signature
 * "One CRM Two Channels" section, ours showing three channels instead.
 *
 * Pure CSS: bubbles appear in a looping sequence (.wm-chat-loop in
 * resources/css/app.css) and the typing indicator uses .wm-typing. No JS
 * beyond the Reveal entrance. Static (all visible) under reduced-motion.
 *
 * Content is intentionally hardcoded — it is a product illustration, not
 * admin-managed copy. The section itself is gated by the
 * `landing.livemock_enabled` setting in Welcome.jsx.
 */

const MESSAGES = [
    { from: 'them', channel: 'whatsapp', text: 'Hi! Is the Pro plan available with a free trial?' },
    { from: 'us', channel: 'whatsapp', text: 'Yes — 14 days free, no card needed. Want me to start it?' },
    { from: 'them', channel: 'messenger', text: 'Perfect, please do! 🎉' },
    { from: 'us', channel: 'instagram', text: 'Done! Your trial is active. I have also emailed the setup guide.' },
    { from: 'them', channel: 'whatsapp', text: 'Amazing — thank you!' },
];

const CHANNEL_COLORS = {
    whatsapp: { bg: 'bg-[#25D366]', soft: 'bg-[#25D366]/12', text: 'text-[#25D366]' },
    messenger: { bg: 'bg-[#0084FF]', soft: 'bg-[#0084FF]/12', text: 'text-[#0084FF]' },
    instagram: { bg: 'bg-[#E1306C]', soft: 'bg-[#E1306C]/12', text: 'text-[#E1306C]' },
};

function ChannelDot({ channel }) {
    const c = CHANNEL_COLORS[channel] || CHANNEL_COLORS.whatsapp;
    return (
        <span className={`inline-flex h-4 w-4 items-center justify-center rounded-full ${c.bg}`}>
            <span className="h-1.5 w-1.5 rounded-full bg-white" />
        </span>
    );
}

export default function LiveChatMock() {
    return (
        <section className="relative bg-[#FAFDF7] py-20 sm:py-28">
            <div className="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
                <div className="text-center mb-14">
                    <Reveal origin="bottom" distance={30}>
                        <span className="inline-flex items-center gap-1.5 rounded-full bg-[#5a8b38]/15 text-[#467235] text-xs font-semibold px-3 py-1 border border-[#5a8b38]/30">
                            <span className="h-1.5 w-1.5 rounded-full bg-[#5a8b38] inline-block" />
                            LIVE UNIFIED INBOX
                        </span>
                        <h2 className="mt-4 text-3xl sm:text-4xl font-bold text-[#0F1A0C] tracking-[-0.02em]">
                            One Inbox, Every Channel
                        </h2>
                        <p className="mt-4 text-base sm:text-lg text-[#4A5B44] max-w-2xl mx-auto">
                            Messages from WhatsApp, Messenger and Instagram flow into a single
                            conversation — zero switching, zero delay, zero missed leads.
                        </p>
                    </Reveal>
                </div>

                <div className="grid gap-8 lg:grid-cols-2 items-center">
                    {/* Phone mockup */}
                    <Reveal origin="left" distance={50} duration={900}>
                        <div className="mx-auto w-full max-w-sm rounded-[2rem] wm-card border-[#E2EBD9] bg-white shadow-2xl overflow-hidden">
                            {/* App bar */}
                            <div className="flex items-center justify-between px-4 py-3 border-b border-[#E9EFE6]" style={{ background: '#162610' }}>
                                <div className="flex items-center gap-2">
                                    <span className="h-8 w-8 rounded-full flex items-center justify-center text-white text-xs font-bold" style={{ background: '#5a8b38' }}>W</span>
                                    <div>
                                        <p className="text-sm font-semibold text-white leading-none">WhatsMine Inbox</p>
                                        <span className="text-[11px] text-[#9ae67a]">● online · 3 channels</span>
                                    </div>
                                </div>
                                <span className="text-[10px] font-bold text-white/70 border border-white/20 rounded px-1.5 py-0.5">LIVE</span>
                            </div>

                            {/* Thread */}
                            <div className="p-4 space-y-3 bg-[#F7FAF1] min-h-[320px]">
                                <div className="wm-chat-loop flex flex-col gap-3">
                                    {MESSAGES.map((m, idx) => (
                                        <div
                                            key={idx}
                                            className={`flex ${m.from === 'us' ? 'justify-end' : 'justify-start'}`}
                                        >
                                            <div className={`max-w-[80%] rounded-2xl px-3.5 py-2 text-sm shadow-sm ${
                                                m.from === 'us'
                                                    ? 'bg-[#5a8b38] text-white rounded-br-md'
                                                    : 'bg-white text-[#0F1A0C] rounded-bl-md border border-[#E2EBD9]'
                                            }`}>
                                                <span className={`inline-flex items-center gap-1 mb-1 ${m.from === 'us' ? 'opacity-80' : CHANNEL_COLORS[m.channel]?.text || ''}`}>
                                                    <ChannelDot channel={m.channel} />
                                                    <span className="text-[10px] font-semibold uppercase tracking-wide">
                                                        {m.channel}
                                                    </span>
                                                </span>
                                                <p className="leading-snug">{m.text}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>

                                {/* Typing indicator */}
                                <div className="flex items-center gap-2 text-[#7A8C6E] text-xs pl-1">
                                    <span className="wm-typing inline-flex items-center">
                                        <span /><span /><span />
                                    </span>
                                    Agent is typing…
                                </div>
                            </div>
                        </div>
                    </Reveal>

                    {/* Benefits */}
                    <Reveal origin="right" distance={50} duration={900} delay={150}>
                        <div className="space-y-5">
                            {[
                                { title: 'Single thread per customer', desc: 'WhatsApp, Messenger and Instagram history for one contact lives in one conversation.' },
                                { title: 'Assign, snooze, collaborate', desc: 'Route conversations to teammates, internal notes, and canned replies — like a helpdesk.' },
                                { title: 'AI drafts replies in your tone', desc: 'The AI assistant suggests answers from your knowledge base; agents approve and send.' },
                                { title: 'Never miss a lead', desc: 'Real-time sync across channels with instant notifications and unread badges.' },
                            ].map((b, idx) => (
                                <div key={idx} className="flex gap-4 items-start">
                                    <span className="mt-0.5 flex-shrink-0 h-6 w-6 rounded-full flex items-center justify-center" style={{ background: 'rgba(118,168,78,0.15)' }}>
                                        <svg className="h-3.5 w-3.5" style={{ color: '#5a8b38' }} fill="none" stroke="currentColor" strokeWidth={3} viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                                        </svg>
                                    </span>
                                    <div>
                                        <h3 className="text-sm font-semibold text-[#0F1A0C]">{b.title}</h3>
                                        <p className="text-sm text-[#4A5B44] leading-relaxed">{b.desc}</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Reveal>
                </div>
            </div>
        </section>
    );
}
