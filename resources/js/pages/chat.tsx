import { useChat } from '@ai-sdk/react';
import { AssistantRuntimeProvider } from '@assistant-ui/react';
import { useAISDKRuntime } from '@assistant-ui/react-ai-sdk';
import { Head, router } from '@inertiajs/react';
import { DefaultChatTransport, type UIMessage } from 'ai';
import { useMemo } from 'react';
import { Thread } from '@/components/assistant-ui/elements/thread.aui';
import {
    NewSourcesHint,
    SourcesStrip,
    WelcomeDialog,
} from '@/components/chat-sources';
import { chat } from '@/routes';
import { stream } from '@/routes/chat';
import type { ChatSources } from '@/types';

type Props = {
    conversationId: string;
    isNew: boolean;
    messages: UIMessage[];
    welcome: boolean;
    sources: ChatSources;
};

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

function Welcome() {
    return (
        <div className="mb-6 flex flex-col gap-4 px-2">
            <div className="flex flex-col gap-2">
                <p className="text-2xl font-medium tracking-tight">
                    What do you want to know?
                </p>
                <p className="text-sm text-muted-foreground">
                    Atlas answers from the code, docs, and other sources
                    connected to it, and cites where each fact came from.
                </p>
            </div>
            <NewSourcesHint />
            <SourcesStrip />
        </div>
    );
}

function Conversation({ conversationId, isNew, messages }: Props) {
    const transport = useMemo(
        () =>
            new DefaultChatTransport({
                api: stream.url(),
                credentials: 'same-origin',
                headers: () => ({
                    'X-XSRF-TOKEN': xsrfToken(),
                    Accept: 'text/event-stream',
                }),
            }),
        [],
    );

    const helpers = useChat({
        id: conversationId,
        messages,
        transport,
        onFinish: () => {
            // The first answer creates the conversation: move to its URL and refresh the sidebar's chat list...
            router.visit(chat.url(conversationId), {
                only: ['conversations', 'isNew'],
                replace: isNew,
                preserveState: true,
                preserveScroll: true,
            });
        },
    });

    const runtime = useAISDKRuntime(helpers);

    return (
        <AssistantRuntimeProvider runtime={runtime}>
            <Thread components={{ Welcome }} />
        </AssistantRuntimeProvider>
    );
}

export default function Chat(props: Props) {
    return (
        <>
            <Head title="Chat" />

            <div className="h-[calc(100svh-4rem)] min-h-0 md:h-[calc(100svh-5rem)]">
                {/* Remount per conversation so useChat starts from that conversation's messages... */}
                <Conversation key={props.conversationId} {...props} />
            </div>

            <WelcomeDialog />
        </>
    );
}

Chat.layout = {
    breadcrumbs: [{ title: 'Chat', href: chat() }],
};
