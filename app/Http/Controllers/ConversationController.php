<?php

namespace App\Http\Controllers;

use App\Ai\Agents\Atlas;
use App\Enums\McpAuth;
use App\Models\McpServer;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Ai\Models\Conversation;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Vercel\Vercel;

class ConversationController extends Controller
{
    /**
     * Show a new chat, or reopen one of the user's conversations.
     */
    public function show(Request $request, ?string $conversation = null): Response
    {
        $existing = $conversation === null ? null : $request->user()->conversations()->findOrFail($conversation);

        return Inertia::render('chat', [
            'conversationId' => $existing->id ?? (string) Str::uuid7(),
            'isNew' => $existing === null,
            'welcome' => $request->user()->welcomed_at === null,
            'sources' => fn () => $this->sources($request->user()),
            'messages' => $existing === null ? [] : Vercel::toUiMessages(
                $existing->messages()->orderBy('created_at')->orderBy('id')->get(),
            ),
        ]);
    }

    /**
     * Stream the agent's answer to a useChat request using the Vercel AI SDK UI message protocol.
     */
    public function stream(Request $request): StreamableAgentResponse
    {
        $request->validate([
            'id' => ['required', 'uuid'],
            'messages' => ['required', 'array', 'min:1'],
        ]);

        $chat = Vercel::chat($request);
        $user = $request->user();
        $id = $request->string('id')->toString();

        // The client's chat id is the conversation id, so the first turn creates the conversation...
        if (! Conversation::whereKey($id)->exists()) {
            $user->conversations()->create([
                'id' => $id,
                'title' => Str::limit($chat->message()->content ?? 'New conversation', 80, preserveWords: true),
            ]);
        }

        abort_unless($user->conversations()->whereKey($id)->exists(), 404);

        return Atlas::make(user: $user)
            ->continue($id, as: $user)
            ->stream($chat)
            ->usingProtocol($chat->protocol());
    }

    /**
     * Summarise what a new chat can read, from this user's point of view.
     *
     * @return array{repositories: int, mcpServers: list<array{id: int, name: string, auth: string, connected: bool, isNew: bool}>}
     */
    protected function sources(User $user): array
    {
        $servers = McpServer::orderBy('name')
            ->withExists(['connections as connected' => fn ($query) => $query->where('user_id', $user->id)])
            ->get(['id', 'name', 'auth', 'created_at']);

        return [
            'repositories' => Repository::count(),
            'mcpServers' => array_values($servers->map(fn (McpServer $server) => [
                'id' => $server->id,
                'name' => $server->name,
                'auth' => $server->auth->value,
                // Servers that work without the user's own account count as connected...
                'connected' => $server->auth !== McpAuth::OAuth || (bool) $server->getAttribute('connected'),
                // Added since the user last saw the list, and still waiting for them to connect...
                'isNew' => $server->auth === McpAuth::OAuth
                    && ! $server->getAttribute('connected')
                    && $user->source_hint_dismissed_at !== null
                    && $server->created_at?->isAfter($user->source_hint_dismissed_at) === true,
            ])->all()),
        ];
    }
}
