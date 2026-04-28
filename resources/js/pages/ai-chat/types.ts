export type Conversation = {
    id: string;
    title: string;
    updated_at: string | null;
};

export type Message = {
    id: string;
    role: 'user' | 'assistant';
    content: string;
    created_at: string | null;
};

export type Props = {
    conversations: Conversation[];
    activeConversationId: string | null;
    messages: Message[];
};

export type StreamEvent =
    | { type: 'delta'; text: string }
    | { type: 'tool'; name: string }
    | { type: 'tool_done' }
    | { type: 'done'; conversation_id: string | null }
    | { type: 'error'; message: string };
