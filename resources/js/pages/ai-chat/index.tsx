import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import { Loader2, MessageSquarePlus, PanelLeft, Send, Sparkles, Trash2, X } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AppLayout from '@/layouts/app-layout';
import AiChatController from '@/actions/App/Http/Controllers/AiChatController';
import MarkdownContent from '@/components/markdown-content';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { index as aiChatIndex, show as aiChatShow, stream as aiChatStream } from '@/routes/ai-chat';

type Conversation = {
    id: string;
    title: string;
    updated_at: string | null;
};

type Message = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    created_at: string | null;
};

type Props = {
    conversations: Conversation[];
    activeConversationId: string | null;
    messages: Message[];
};

type StreamEvent =
    | { type: 'delta'; text: string }
    | { type: 'tool'; name: string }
    | { type: 'tool_done' }
    | { type: 'done'; conversation_id: string | null }
    | { type: 'error'; message: string };

function getCsrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

export default function AiChatIndex({ conversations, activeConversationId, messages }: Props) {
    const [draft, setDraft] = useState('');
    const [pendingUser, setPendingUser] = useState<string | null>(null);
    const [streamText, setStreamText] = useState('');
    const [streamPhase, setStreamPhase] = useState<'idle' | 'running' | 'tool'>('idle');
    const [activeTool, setActiveTool] = useState<string | null>(null);
    const [errorText, setErrorText] = useState<string | null>(null);
    const [sidebarOpen, setSidebarOpen] = useState(false);
    const threadRef = useRef<HTMLDivElement | null>(null);
    const abortRef = useRef<AbortController | null>(null);

    useEffect(() => {
        setSidebarOpen(false);
    }, [activeConversationId]);

    const scrollToBottom = useCallback(() => {
        if (threadRef.current) {
            threadRef.current.scrollTop = threadRef.current.scrollHeight;
        }
    }, []);

    useEffect(() => {
        scrollToBottom();
    }, [messages.length, activeConversationId, scrollToBottom]);

    useEffect(() => {
        scrollToBottom();
    }, [streamText, pendingUser, scrollToBottom]);

    useEffect(() => {
        return () => {
            abortRef.current?.abort();
        };
    }, []);

    const busy = streamPhase !== 'idle';

    const startNew = () => {
        if (busy) return;
        router.visit(aiChatIndex().url);
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        const message = draft.trim();
        if (! message || busy) return;

        setErrorText(null);
        setPendingUser(message);
        setStreamText('');
        setStreamPhase('running');
        setActiveTool(null);
        setDraft('');

        const controller = new AbortController();
        abortRef.current = controller;

        try {
            const response = await fetch(aiChatStream().url, {
                method: 'POST',
                headers: {
                    Accept: 'text/event-stream',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                signal: controller.signal,
                body: JSON.stringify({
                    message,
                    conversation_id: activeConversationId ?? null,
                }),
            });

            if (! response.ok || ! response.body) {
                throw new Error(`HTTP ${response.status}`);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let finalConversationId: string | null = activeConversationId ?? null;
            let sawError: string | null = null;

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                let boundary = buffer.indexOf('\n\n');
                while (boundary !== -1) {
                    const chunk = buffer.slice(0, boundary);
                    buffer = buffer.slice(boundary + 2);
                    boundary = buffer.indexOf('\n\n');

                    for (const line of chunk.split('\n')) {
                        if (! line.startsWith('data:')) continue;
                        const payload = line.slice(5).trim();
                        if (! payload) continue;
                        let ev: StreamEvent;
                        try {
                            ev = JSON.parse(payload) as StreamEvent;
                        } catch {
                            continue;
                        }

                        if (ev.type === 'delta') {
                            setStreamPhase('running');
                            setActiveTool(null);
                            setStreamText((prev) => prev + ev.text);
                        } else if (ev.type === 'tool') {
                            setStreamPhase('tool');
                            setActiveTool(ev.name);
                        } else if (ev.type === 'tool_done') {
                            setActiveTool(null);
                            setStreamPhase('running');
                        } else if (ev.type === 'done') {
                            finalConversationId = ev.conversation_id ?? finalConversationId;
                        } else if (ev.type === 'error') {
                            sawError = ev.message;
                        }
                    }
                }
            }

            if (sawError) {
                throw new Error(sawError);
            }

            if (finalConversationId && finalConversationId !== activeConversationId) {
                router.visit(aiChatShow(finalConversationId).url, {
                    preserveScroll: true,
                    replace: false,
                });
            } else {
                router.reload({ only: ['conversations', 'messages'], preserveScroll: true });
            }
        } catch (err) {
            if ((err as Error).name === 'AbortError') {
                // swallow
            } else {
                setErrorText((err as Error).message || 'A apărut o eroare.');
            }
        } finally {
            setStreamPhase('idle');
            setActiveTool(null);
            setPendingUser(null);
            setStreamText('');
            abortRef.current = null;
        }
    };

    const handleStop = () => {
        abortRef.current?.abort();
    };

    const statusLabel = useMemo(() => {
        if (streamPhase === 'tool' && activeTool) return `Rulez ${activeTool}…`;
        if (streamPhase === 'running') return 'Scriu răspunsul…';
        return '';
    }, [streamPhase, activeTool]);

    const activeTitle = useMemo(() => {
        if (! activeConversationId) return 'Conversație nouă';
        return conversations.find((c) => c.id === activeConversationId)?.title ?? 'Asistent AI';
    }, [activeConversationId, conversations]);

    const sidebar = (
        <div className="flex h-full flex-col gap-2 p-3">
            <div className="flex items-center justify-between gap-2 md:block">
                <h2 className="text-xs font-semibold uppercase tracking-wide text-muted-foreground md:hidden">
                    Conversații
                </h2>
                <button
                    type="button"
                    onClick={() => setSidebarOpen(false)}
                    className="rounded-md p-1 text-muted-foreground hover:bg-muted md:hidden"
                    aria-label="Închide"
                >
                    <X className="size-4" />
                </button>
            </div>
            <Button variant="secondary" size="sm" onClick={startNew} disabled={busy}>
                <MessageSquarePlus />
                Conversație nouă
            </Button>
            <div className="flex-1 overflow-y-auto">
                <ul className="flex flex-col gap-1">
                    {conversations.length === 0 && (
                        <li className="px-2 py-1 text-xs text-muted-foreground">
                            Nicio conversație.
                        </li>
                    )}
                    {conversations.map((c) => (
                        <li key={c.id} className="group flex items-center gap-1">
                            <Link
                                href={aiChatShow(c.id)}
                                className={
                                    'flex-1 truncate rounded-md px-2 py-2 text-xs hover:bg-muted md:py-1.5 ' +
                                    (c.id === activeConversationId ? 'bg-muted font-medium' : '')
                                }
                            >
                                {c.title}
                            </Link>
                            <Form
                                {...AiChatController.destroy.form(c.id)}
                                options={{ preserveScroll: true }}
                                onBefore={() => confirm('Ștergi conversația?')}
                            >
                                {({ processing }) => (
                                    <button
                                        type="submit"
                                        className="rounded-md p-1.5 text-muted-foreground hover:bg-muted hover:text-destructive disabled:opacity-50 md:p-1 md:opacity-0 md:group-hover:opacity-100"
                                        disabled={processing || busy}
                                        aria-label="Șterge"
                                    >
                                        <Trash2 className="size-3.5" />
                                    </button>
                                )}
                            </Form>
                        </li>
                    ))}
                </ul>
            </div>
        </div>
    );

    return (
        <>
            <Head title="Asistent AI" />

            <div className="flex h-[100dvh] flex-1 flex-col md:grid md:h-[calc(100vh-4rem)] md:grid-cols-[260px_1fr]">
                <aside className="hidden border-r border-sidebar-border/70 md:block dark:border-sidebar-border">
                    {sidebar}
                </aside>

                {sidebarOpen && (
                    <div className="fixed inset-0 z-50 flex md:hidden">
                        <div className="relative flex w-[84%] max-w-[320px] flex-col bg-background shadow-xl">
                            {sidebar}
                        </div>
                        <button
                            type="button"
                            aria-label="Închide"
                            className="flex-1 bg-black/40"
                            onClick={() => setSidebarOpen(false)}
                        />
                    </div>
                )}

                <section className="flex min-h-0 flex-1 flex-col">
                    <header className="flex items-center gap-2 border-b border-sidebar-border/70 px-3 py-2 md:hidden dark:border-sidebar-border">
                        <button
                            type="button"
                            onClick={() => setSidebarOpen(true)}
                            className="rounded-md p-2 text-muted-foreground hover:bg-muted"
                            aria-label="Conversații"
                        >
                            <PanelLeft className="size-4" />
                        </button>
                        <h1 className="flex-1 truncate text-sm font-medium">{activeTitle}</h1>
                        <button
                            type="button"
                            onClick={startNew}
                            disabled={busy}
                            className="rounded-md p-2 text-muted-foreground hover:bg-muted disabled:opacity-50"
                            aria-label="Conversație nouă"
                        >
                            <MessageSquarePlus className="size-4" />
                        </button>
                    </header>

                    <div
                        ref={threadRef}
                        className="flex-1 overflow-y-auto px-3 py-4 md:p-4"
                    >
                        {messages.length === 0 && ! pendingUser && <EmptyState />}
                        <div className="mx-auto flex max-w-3xl flex-col gap-3 md:gap-4">
                            {messages.map((m) => (
                                <MessageBubble key={m.id} message={m} />
                            ))}
                            {pendingUser && (
                                <MessageBubble
                                    message={{
                                        id: 'pending-user',
                                        role: 'user',
                                        content: pendingUser,
                                        created_at: null,
                                    }}
                                />
                            )}
                            {(streamText || streamPhase !== 'idle') && (
                                <StreamingBubble text={streamText} status={statusLabel} />
                            )}
                            {errorText && (
                                <div className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
                                    {errorText}
                                </div>
                            )}
                        </div>
                    </div>

                    <div
                        className="border-t border-sidebar-border/70 px-3 py-3 dark:border-sidebar-border"
                        style={{ paddingBottom: 'max(0.75rem, env(safe-area-inset-bottom))' }}
                    >
                        <form
                            onSubmit={handleSubmit}
                            className="mx-auto flex max-w-3xl items-end gap-2"
                        >
                            <textarea
                                value={draft}
                                onChange={(e) => setDraft(e.target.value)}
                                rows={1}
                                required
                                disabled={busy}
                                placeholder="Întreabă…"
                                className="min-h-[2.75rem] max-h-40 w-full resize-none rounded-lg border border-sidebar-border/70 bg-background px-3 py-2 text-base focus:outline-none focus:ring-2 focus:ring-ring disabled:opacity-50 md:min-h-[4.5rem] md:text-sm dark:border-sidebar-border"
                                onKeyDown={(e) => {
                                    if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) {
                                        (e.target as HTMLTextAreaElement).form?.requestSubmit();
                                    }
                                }}
                            />
                            {busy ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    onClick={handleStop}
                                    className="md:size-auto md:px-4"
                                    aria-label="Oprește"
                                >
                                    <X className="md:hidden" />
                                    <span className="hidden md:inline">Oprește</span>
                                </Button>
                            ) : (
                                <Button
                                    type="submit"
                                    size="icon"
                                    disabled={! draft.trim()}
                                    className="md:size-auto md:px-4"
                                    aria-label="Trimite"
                                >
                                    <Send />
                                    <span className="hidden md:inline">Trimite</span>
                                </Button>
                            )}
                        </form>
                        <p className="mx-auto mt-1 hidden max-w-3xl text-[10px] text-muted-foreground md:block">
                            Ctrl/⌘ + Enter trimite. Răspunsul apare în timp real; interogările complexe pot dura 10–60s.
                        </p>
                    </div>
                </section>
            </div>
        </>
    );
}

function MessageBubble({ message }: { message: Message }) {
    const isUser = message.role === 'user';
    return (
        <div className={'flex ' + (isUser ? 'justify-end' : 'justify-start')}>
            <Card
                className={
                    'max-w-[92%] px-3 py-2 text-sm md:max-w-[85%] ' +
                    (isUser ? 'whitespace-pre-wrap bg-primary text-primary-foreground' : '')
                }
            >
                {isUser ? message.content : <MarkdownContent content={message.content} />}
            </Card>
        </div>
    );
}

function StreamingBubble({ text, status }: { text: string; status: string }) {
    return (
        <div className="flex justify-start">
            <Card className="max-w-[92%] px-3 py-2 text-sm md:max-w-[85%]">
                {text ? (
                    <MarkdownContent content={text} />
                ) : (
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                        <Loader2 className="size-3.5 animate-spin" />
                        {status || 'Gândesc…'}
                    </div>
                )}
                {text && status && (
                    <div className="mt-2 flex items-center gap-1.5 text-[10px] text-muted-foreground">
                        <Loader2 className="size-3 animate-spin" />
                        {status}
                    </div>
                )}
            </Card>
        </div>
    );
}

function EmptyState() {
    const suggestions = [
        'Top 10 furnizori după valoare pentru Christian Tour în 2025',
        'Cash-flow lunar (încasări vs plăți) pentru 2025',
        'Facturi furnizor cu scadența depășită, ordonate după zile întârziate',
        'Parteneri noi adăugați în ultimele 90 zile',
    ];

    return (
        <div className="mx-auto flex max-w-2xl flex-col items-center gap-4 px-2 pt-8 text-center md:pt-12">
            <div className="flex size-12 items-center justify-center rounded-full bg-muted">
                <Sparkles className="size-6 text-muted-foreground" />
            </div>
            <div>
                <h1 className="text-lg font-semibold">Asistent financiar AI</h1>
                <p className="text-sm text-muted-foreground">
                    Întreabă despre facturi, plăți, cash-flow sau parteneri. Agentul execută SELECT-uri
                    read-only pe baza companiei alese și răspunde în română.
                </p>
            </div>
            <ul className="grid w-full gap-2 sm:grid-cols-2">
                {suggestions.map((s) => (
                    <li
                        key={s}
                        className="rounded-md border border-sidebar-border/70 px-3 py-2 text-left text-xs text-muted-foreground dark:border-sidebar-border"
                    >
                        {s}
                    </li>
                ))}
            </ul>
        </div>
    );
}

function AiChatLayout({ children }: { children: React.ReactNode }) {
    const { activeConversationId } = usePage<Props>().props;
    return (
        <AppLayout
            breadcrumbs={[
                {
                    title: 'Asistent AI',
                    href: activeConversationId ? aiChatShow(activeConversationId) : aiChatIndex(),
                },
            ]}
        >
            {children}
        </AppLayout>
    );
}

AiChatIndex.layout = (page: React.ReactNode) => <AiChatLayout>{page}</AiChatLayout>;
