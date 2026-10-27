<?php

namespace App\Services\GitHub;

use App\Models\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Reads GitHub with a single access token (GITHUB_TOKEN): a fine-grained or classic personal access token.
 */
class TokenProvider extends GitHubProvider
{
    public function configured(): bool
    {
        return filled(config('services.github.token'));
    }

    public function client(?Repository $repository = null): PendingRequest
    {
        if (! $this->configured()) {
            throw new RuntimeException('GitHub is not configured. Set GITHUB_TOKEN.');
        }

        return $this->request()->withToken((string) config('services.github.token'));
    }

    public function accounts(): array
    {
        return [$this->account('token', 'Access token', $this->accessibleRepositories(...), Repository::orderBy('full_name')->get())];
    }

    public function add(string $account, int $githubId): ?Repository
    {
        $granted = $this->accessibleRepositories()->firstWhere('id', $githubId);

        return $granted === null ? null : Repository::firstOrCreate(
            ['github_id' => $granted['id']],
            ['full_name' => $granted['full_name']],
        );
    }

    public function sync(): void
    {
        $this->reconcile(Repository::all(), $this->accessibleRepositories());
    }

    public function searchAcross(Collection $repositories, callable $search): array
    {
        return array_values($search($this->client(), $this->qualifier($repositories)));
    }

    /**
     * List every repository the token can read, including through organisation membership.
     *
     * @return Collection<int, array{id: int, full_name: string, private: bool}>
     */
    protected function accessibleRepositories(): Collection
    {
        return $this->summarise($this->paginate($this->client(), '/user/repos', query: [
            'affiliation' => 'owner,collaborator,organization_member',
        ]));
    }
}
