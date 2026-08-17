import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import PageHeader from '@/Components/PageHeader';
import { Head, useForm, router } from '@inertiajs/react';
import { SparklesIcon, PaperAirplaneIcon, ChatBubbleLeftIcon } from '@heroicons/react/24/outline';
import { useState } from 'react';

export default function CopilotIndex({ conversations = [], active, messages = [] }) {
    const [input, setInput] = useState('');
    const { data, setData, post, processing, reset } = useForm({
        conversation_id: active?.id || null,
        content: '',
    });

    const send = (e) => {
        e.preventDefault();
        if (!data.content.trim()) return;
        post(route('copilot.send'), { preserveScroll: true, onSuccess: () => reset('content') });
    };

    const suggestions = [
        'Show me overdue critical risks',
        'Which controls failed CCM this week?',
        'Summarise the latest CBN circular',
        'Draft NDPC 72-hour breach notice for INC-001',
        'List top 5 KRIs in red',
        'Which vendors have security ratings below 70?',
    ];

    return (
        <AuthenticatedLayout header="Atheris Copilot">
            <Head title="Atheris Copilot" />
            <PageHeader
                breadcrumbs={[{ label: 'Atheris Copilot' }]}
                title="Atheris Copilot"
                subtitle="Natural-language assistant grounded in your risks, controls, policies, incidents, obligations and KRIs."
                actions={<span className="inline-flex items-center gap-1 px-3 py-1 rounded-full bg-[#C9A86A]/15 text-[#0A1F44] text-xs font-medium"><SparklesIcon className="w-4 h-4" /> Claude Sonnet 4.6 / Ollama qwen2.5:14b</span>}
            />

            <div className="grid grid-cols-1 lg:grid-cols-4 gap-4">
                <aside className="bg-white rounded-xl border border-gray-100 shadow-sm p-3 h-fit">
                    <h3 className="text-xs font-semibold uppercase text-[#718096] tracking-wider mb-2">Conversations</h3>
                    <ul className="space-y-1">
                        {conversations.length === 0 && (
                            <li className="text-xs text-[#718096] px-2 py-3">No conversations yet.</li>
                        )}
                        {conversations.map((c) => (
                            <li key={c.id}>
                                <button
                                    onClick={() => router.get(route('copilot.index'), { c: c.id })}
                                    className={`w-full text-left px-2 py-2 rounded-md text-sm flex items-start gap-2 hover:bg-gray-50
                                        ${active?.id === c.id ? 'bg-[#0A1F44]/5 border border-[#0A1F44]/10' : ''}`}>
                                    <ChatBubbleLeftIcon className="w-4 h-4 text-[#0A1F44] mt-0.5" />
                                    <span className="line-clamp-2 text-[#2D3748]">{c.title}</span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </aside>

                <section className="lg:col-span-3 bg-white rounded-xl border border-gray-100 shadow-sm flex flex-col min-h-[60vh]">
                    <div className="flex-1 overflow-y-auto p-4 space-y-4">
                        {messages.length === 0 && (
                            <div className="text-center p-8">
                                <SparklesIcon className="w-10 h-10 mx-auto text-[#C9A86A]" />
                                <p className="mt-3 text-sm text-[#2D3748] font-medium">Ask the Copilot anything about your GRC estate.</p>
                                <div className="mt-4 flex flex-wrap gap-2 justify-center">
                                    {suggestions.map((s) => (
                                        <button key={s}
                                            onClick={() => setData('content', s)}
                                            className="px-3 py-1.5 rounded-full bg-[#0A1F44]/5 text-[#0A1F44] text-xs hover:bg-[#0A1F44]/10">
                                            {s}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}
                        {messages.map((m) => (
                            <div key={m.id} className={`flex ${m.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                                <div className={`max-w-2xl px-4 py-2 rounded-xl text-sm whitespace-pre-wrap
                                    ${m.role === 'user' ? 'bg-[#0A1F44] text-white' : 'bg-gray-100 text-[#2D3748]'}`}>
                                    {m.content}
                                </div>
                            </div>
                        ))}
                    </div>
                    <form onSubmit={send} className="border-t border-gray-100 p-3 flex gap-2">
                        <input
                            value={data.content}
                            onChange={(e) => setData('content', e.target.value)}
                            placeholder="Ask the Copilot…"
                            className="flex-1 px-3 py-2 rounded-lg border border-gray-200 focus:ring-2 focus:ring-[#0A1F44]/30 text-sm"
                        />
                        <button type="submit" disabled={processing}
                            className="px-4 py-2 rounded-lg bg-[#0A1F44] text-white flex items-center gap-1 hover:bg-[#1A2F54] disabled:opacity-60">
                            <PaperAirplaneIcon className="w-4 h-4" /> Send
                        </button>
                    </form>
                </section>
            </div>
        </AuthenticatedLayout>
    );
}
