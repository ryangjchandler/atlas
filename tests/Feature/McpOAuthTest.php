<?php

namespace Tests\Feature;

use App\Ai\Agents\Atlas;
use App\Enums\McpAuth;
use App\Models\McpConnection;
use App\Models\McpServer;
use App\Models\User;
use App\Services\Mcp\McpOAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpOAuthTest extends TestCase
{
    use RefreshDatabase;

    protected int $tokensIssued = 0;

    protected function setUp(): void
    {
        parent::setUp();

        // A spec-compliant MCP server with dynamic client registration...
        Http::fake([
            'docs.example.com/.well-known/oauth-protected-resource/mcp' => Http::response([
                'resource' => 'https://docs.example.com/mcp',
                'authorization_servers' => ['https://auth.example.com'],
            ]),
            'auth.example.com/.well-known/oauth-authorization-server' => Http::response([
                'issuer' => 'https://auth.example.com',
                'authorization_endpoint' => 'https://auth.example.com/authorize',
                'token_endpoint' => 'https://auth.example.com/token',
                'registration_endpoint' => 'https://auth.example.com/register',
                'code_challenge_methods_supported' => ['S256'],
            ]),
            'auth.example.com/register' => Http::response(['client_id' => 'dyn-client', 'client_secret' => 'dyn-secret'], 201),
            'auth.example.com/token' => function () {
                $this->tokensIssued++;

                return Http::response([
                    'access_token' => "access-{$this->tokensIssued}",
                    'refresh_token' => "refresh-{$this->tokensIssued}",
                    'expires_in' => 3600,
                ]);
            },
        ]);
    }

    protected function oauthServer(): McpServer
    {
        return McpServer::create(['name' => 'Docs', 'url' => 'https://docs.example.com/mcp', 'auth' => McpAuth::OAuth]);
    }

    public function test_a_user_connects_their_own_account_and_tokens_are_stored_encrypted(): void
    {
        $server = $this->oauthServer();
        $user = User::factory()->create();

        // Connecting from a new chat returns there afterwards...
        $redirect = $this->actingAs($user)
            ->withHeader('Referer', route('chat'))
            ->get(route('mcp-servers.oauth.connect', $server))
            ->assertRedirect();

        $location = $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://auth.example.com/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('dyn-client', $query['client_id']);
        $this->assertSame(route('mcp-servers.oauth.callback', $server), $query['redirect_uri']);

        $this->actingAs($user)
            ->get(route('mcp-servers.oauth.callback', [$server, 'code' => 'abc', 'state' => $query['state']]))
            ->assertRedirect(route('chat'));

        $connection = McpConnection::sole();
        $this->assertSame($user->id, $connection->user_id);
        $this->assertSame('access-1', $connection->access_token);
        $this->assertSame('dyn-client', $connection->client_id);
        $this->assertStringNotContainsString('access-1', (string) DB::table('mcp_connections')->value('access_token'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.example.com/token'
            && $request['grant_type'] === 'authorization_code'
            && isset($request['code_verifier']));
    }

    public function test_a_callback_with_a_forged_state_is_rejected(): void
    {
        $server = $this->oauthServer();
        $user = User::factory()->create();

        // A link from another site can't choose where the user lands afterwards...
        $this->actingAs($user)->withHeader('Referer', 'https://evil.example.com/')->get(route('mcp-servers.oauth.connect', $server));
        $this->actingAs($user)
            ->get(route('mcp-servers.oauth.callback', [$server, 'code' => 'abc', 'state' => 'forged']))
            ->assertRedirect(route('sources'));

        $this->assertSame(0, McpConnection::count());
    }

    public function test_expired_tokens_are_refreshed_with_the_client_they_were_issued_to(): void
    {
        $server = $this->oauthServer();
        $user = User::factory()->create();

        McpConnection::create([
            'user_id' => $user->id, 'mcp_server_id' => $server->id,
            'access_token' => 'stale', 'refresh_token' => 'refresh-0', 'expires_at' => now()->subMinute(),
            'client_id' => 'dyn-client', 'client_secret' => 'dyn-secret',
        ]);

        $this->assertSame('access-1', app(McpOAuth::class)->accessToken($server, $user));
        $this->assertSame('access-1', app(McpOAuth::class)->accessToken($server, $user));
        $this->assertSame(1, $this->tokensIssued);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://auth.example.com/token'
            && $request['grant_type'] === 'refresh_token'
            && $request['refresh_token'] === 'refresh-0'
            && $request['client_id'] === 'dyn-client');
    }

    public function test_the_agent_only_uses_oauth_servers_the_asking_user_has_connected(): void
    {
        $server = $this->oauthServer();
        $connected = User::factory()->create();
        $other = User::factory()->create();

        McpConnection::create([
            'user_id' => $connected->id, 'mcp_server_id' => $server->id,
            'access_token' => 'theirs', 'expires_at' => now()->addHour(),
        ]);

        $this->assertStringNotContainsString('has not connected', (string) (new Atlas($connected))->instructions());
        $this->assertStringContainsString('Docs (unavailable: the user has not connected', (string) (new Atlas($other))->instructions());
        $this->assertSame([], iterator_to_array((new Atlas($other))->tools()));
    }

    public function test_disconnecting_only_removes_the_current_users_connection(): void
    {
        $server = $this->oauthServer();
        [$me, $colleague] = User::factory()->count(2)->create();

        foreach ([$me, $colleague] as $user) {
            McpConnection::create(['user_id' => $user->id, 'mcp_server_id' => $server->id, 'access_token' => 't']);
        }

        $this->actingAs($me)->delete(route('mcp-servers.oauth.disconnect', $server))->assertRedirect();

        $this->assertSame([$colleague->id], McpConnection::pluck('user_id')->all());
    }
}
