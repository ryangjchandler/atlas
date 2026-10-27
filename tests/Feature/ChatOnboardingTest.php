<?php

namespace Tests\Feature;

use App\Enums\McpAuth;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_are_welcomed_once_and_see_which_servers_they_still_need_to_connect(): void
    {
        $user = User::factory()->create();
        McpServer::create(['name' => 'Linear', 'url' => 'https://linear.example.com/mcp', 'auth' => McpAuth::OAuth]);
        McpServer::create(['name' => 'Docs', 'url' => 'https://docs.example.com/mcp', 'auth' => McpAuth::None]);

        $this->actingAs($user)->get(route('chat'))->assertInertia(fn ($page) => $page
            ->where('welcome', true)
            ->where('sources.mcpServers.0.name', 'Docs')
            ->where('sources.mcpServers.0.connected', true)
            ->where('sources.mcpServers.1.name', 'Linear')
            ->where('sources.mcpServers.1.connected', false)
            // Everything is in the welcome, so nothing is announced as new...
            ->where('sources.mcpServers.1.isNew', false));

        $this->actingAs($user)->post(route('onboarding.welcome'))->assertRedirect();

        $this->actingAs($user)->get(route('chat'))->assertInertia(fn ($page) => $page->where('welcome', false));
    }

    public function test_oauth_servers_added_after_the_hint_was_dismissed_are_announced_until_dismissed_again(): void
    {
        $user = User::factory()->create(['welcomed_at' => now()->subDay(), 'source_hint_dismissed_at' => now()->subDay()]);
        $connected = User::factory()->create(['welcomed_at' => now()->subDay(), 'source_hint_dismissed_at' => now()->subDay()]);

        $this->travel(1)->minutes();
        $linear = McpServer::create(['name' => 'Linear', 'url' => 'https://linear.example.com/mcp', 'auth' => McpAuth::OAuth]);
        McpServer::create(['name' => 'Docs', 'url' => 'https://docs.example.com/mcp', 'auth' => McpAuth::Token, 'token' => 'secret']);
        $linear->connections()->create(['user_id' => $connected->id, 'access_token' => 'token', 'client_id' => 'client']);

        $isNew = fn (User $user) => collect($this->actingAs($user)->get(route('chat'))->viewData('page')['props']['sources']['mcpServers'])
            ->where('isNew', true)->pluck('name')->all();

        // Only OAuth servers the user hasn't connected need their attention...
        $this->assertSame(['Linear'], $isNew($user));
        $this->assertSame([], $isNew($connected));

        $this->travel(1)->minutes();
        $this->actingAs($user)->post(route('onboarding.source-hint'))->assertRedirect();
        $this->assertSame([], $isNew($user));

        // ...until another one is added.
        $this->travel(1)->minutes();
        McpServer::create(['name' => 'Zendesk', 'url' => 'https://zendesk.example.com/mcp', 'auth' => McpAuth::OAuth]);
        $this->assertSame(['Zendesk'], $isNew($user));
    }
}
