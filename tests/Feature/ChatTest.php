<?php

namespace Tests\Feature;

use App\Ai\Agents\Atlas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Ai\Models\Conversation;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function chatPayload(string $id, string $text): array
    {
        return [
            'id' => $id,
            'messages' => [
                ['id' => 'm1', 'role' => 'user', 'parts' => [['type' => 'text', 'text' => $text]]],
            ],
        ];
    }

    /**
     * Join the text deltas of a Vercel UI message stream.
     */
    protected function streamedText(string $stream): string
    {
        return collect(explode("\n\n", $stream))
            ->map(fn (string $event) => json_decode(Str::after($event, 'data: '), true))
            ->filter(fn ($part) => is_array($part) && $part['type'] === 'text-delta')
            ->pluck('delta')
            ->implode('');
    }

    public function test_first_message_creates_a_conversation_and_streams_the_answer(): void
    {
        Atlas::fake(['Refunds are processed by RefundService.']);

        $user = User::factory()->create();
        $id = (string) Str::uuid7();

        $response = $this->actingAs($user)
            ->post(route('chat.stream'), $this->chatPayload($id, 'How are refunds processed?'));

        $response->assertOk();
        $this->assertSame('Refunds are processed by RefundService.', $this->streamedText($response->streamedContent()));

        $conversation = Conversation::findOrFail($id);
        $this->assertSame('How are refunds processed?', $conversation->title);
        $this->assertSame(['user', 'assistant'], $conversation->messages()->orderBy('created_at')->orderBy('id')->pluck('role')->all());

        $this->actingAs($user)
            ->get(route('chat', $id))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('chat')
                ->where('conversationId', $id)
                ->where('isNew', false)
                ->has('messages', 2)
                ->has('conversations', 1));
    }

    public function test_users_cannot_open_or_continue_another_users_conversation(): void
    {
        Atlas::fake(['answer', 'answer']);

        $owner = User::factory()->create();
        $intruder = User::factory()->create();
        $id = (string) Str::uuid7();

        $this->actingAs($owner)
            ->post(route('chat.stream'), $this->chatPayload($id, 'hi'))
            ->assertOk()
            ->streamedContent();

        $this->actingAs($intruder)->get(route('chat', $id))->assertNotFound();

        $this->actingAs($intruder)
            ->post(route('chat.stream'), $this->chatPayload($id, 'hi'))
            ->assertNotFound();

        $this->actingAs($intruder)
            ->get(route('chat'))
            ->assertInertia(fn ($page) => $page->has('conversations', 0));
    }
}
