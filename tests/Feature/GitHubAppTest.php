<?php

namespace Tests\Feature;

use App\Jobs\IndexRepository;
use App\Models\GitHubInstallation;
use App\Models\User;
use App\Services\GitHub\AppProvider;
use App\Services\GitHub\GitHubProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ConfiguresGitHubApp;
use Tests\TestCase;

class GitHubAppTest extends TestCase
{
    use ConfiguresGitHubApp, RefreshDatabase;

    protected string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicKey = $this->configureGitHubApp();

        Http::fake([
            'api.github.com/app/installations?*' => Http::response([
                ['id' => 1001, 'account' => ['login' => 'acme', 'type' => 'Organization']],
            ]),
            'api.github.com/app/installations/1001/access_tokens' => Http::response(['token' => 'ghs_acme', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            'api.github.com/installation/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
                ['id' => 1, 'full_name' => 'acme/billing-v2', 'private' => true],
                ['id' => 2, 'full_name' => 'acme/docs', 'private' => false],
            ]]),
        ]);
    }

    public function test_the_install_callback_syncs_installations_from_github_and_ignores_the_query_string(): void
    {
        $stale = GitHubInstallation::create(['github_id' => 999, 'account_login' => 'gone', 'account_type' => 'User']);
        $stale->repositories()->create(['github_id' => 9, 'full_name' => 'gone/app']);

        $acme = GitHubInstallation::create(['github_id' => 1001, 'account_login' => 'acme-old', 'account_type' => 'Organization']);
        $renamed = $acme->repositories()->create(['github_id' => 1, 'full_name' => 'acme/billing']);
        $revoked = $acme->repositories()->create(['github_id' => 3, 'full_name' => 'acme/secret']);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('github.callback', ['installation_id' => 555, 'setup_action' => 'install']))
            ->assertRedirect(route('sources'));

        $this->assertSame(['acme'], GitHubInstallation::pluck('account_login')->all());
        $this->assertSame('acme/billing-v2', $renamed->fresh()->full_name);
        $this->assertNull($revoked->fresh());
        $this->assertDatabaseMissing('repositories', ['full_name' => 'gone/app']);
    }

    public function test_only_repositories_granted_to_the_installation_can_be_added(): void
    {
        Queue::fake();
        app(GitHubProvider::class)->sync();
        $installation = GitHubInstallation::sole();
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('repositories.store'), ['account' => (string) $installation->id, 'github_id' => 42])
            ->assertSessionHasErrors('github_id');

        $this->actingAs($user)
            ->post(route('repositories.store'), ['account' => (string) $installation->id, 'github_id' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame(['acme/docs'], $installation->repositories()->pluck('full_name')->all());
        Queue::assertPushed(IndexRepository::class, 1);
    }

    public function test_installation_tokens_are_requested_with_a_signed_app_jwt_and_cached(): void
    {
        app(GitHubProvider::class)->sync();
        $installation = GitHubInstallation::sole();

        app(AppProvider::class)->forInstallation($installation)->get('/repos/acme/docs');
        app(AppProvider::class)->forInstallation($installation)->get('/repos/acme/docs');

        $tokenRequests = Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/access_tokens'));
        $this->assertCount(1, $tokenRequests);

        [$header, $payload, $signature] = explode('.', substr($tokenRequests->first()[0]->header('Authorization')[0], strlen('Bearer ')));
        $decode = fn (string $part) => base64_decode(strtr($part, '-_', '+/'));

        $this->assertSame(1, openssl_verify("{$header}.{$payload}", $decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256));
        $claims = json_decode($decode($payload), true);
        $this->assertSame('12345', $claims['iss']);
        $this->assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.github.com/repos/acme/docs'
            && $request->header('Authorization') === ['Bearer ghs_acme']);
    }
}
