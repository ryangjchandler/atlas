<?php

namespace Tests\Feature;

use App\Ai\Tools\SearchGitHubCode;
use App\Jobs\IndexRepository;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request as ToolRequest;
use Tests\TestCase;

class GitHubTokenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.github.driver' => 'token', 'services.github.token' => 'ghp_test']);

        Http::fake([
            'api.github.com/user/repos*' => Http::response([
                ['id' => 2, 'full_name' => 'acme/docs', 'private' => false],
                ['id' => 1, 'full_name' => 'acme/billing-v2', 'private' => true],
            ]),
            'api.github.com/search/code*' => Http::response(['items' => [
                ['repository' => ['full_name' => 'acme/docs'], 'path' => 'README.md', 'html_url' => 'https://github.com/acme/docs/blob/main/README.md'],
            ]]),
        ]);
    }

    public function test_admins_see_and_can_add_only_repositories_the_token_can_access(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('sources'))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('github.driver', 'token')
                ->where('accounts.0.repositories.0.full_name', 'acme/billing-v2')
                ->has('accounts.0.repositories', 2));

        $this->actingAs($admin)
            ->post(route('repositories.store'), ['account' => 'token', 'github_id' => 42])
            ->assertSessionHasErrors('github_id');

        $this->actingAs($admin)
            ->post(route('repositories.store'), ['account' => 'token', 'github_id' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame(['acme/docs'], Repository::pluck('full_name')->all());
        $this->assertNull(Repository::sole()->github_installation_id);
        Queue::assertPushed(IndexRepository::class, 1);
        Http::assertSent(fn (Request $request) => str_starts_with($request->url(), 'https://api.github.com/user/repos')
            && $request->header('Authorization') === ['Bearer ghp_test']);
    }

    public function test_refreshing_access_renames_and_drops_repositories(): void
    {
        $renamed = Repository::create(['github_id' => 1, 'full_name' => 'acme/billing']);
        $revoked = Repository::create(['github_id' => 3, 'full_name' => 'acme/secret']);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('github.sync'))
            ->assertRedirect(route('sources'));

        $this->assertSame('acme/billing-v2', $renamed->fresh()->full_name);
        $this->assertNull($revoked->fresh());
    }

    public function test_searches_cover_every_repository_in_one_request(): void
    {
        $repositories = collect([
            Repository::create(['github_id' => 1, 'full_name' => 'acme/billing-v2']),
            Repository::create(['github_id' => 2, 'full_name' => 'acme/docs']),
        ]);

        $result = (string) (new SearchGitHubCode($repositories))->handle(new ToolRequest(['query' => 'readme']));

        $this->assertStringContainsString('README.md', $result);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request['q'] === 'readme repo:acme/billing-v2 repo:acme/docs');
    }
}
