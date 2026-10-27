<?php

namespace App\Services\GitHub;

use App\Models\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * How Atlas authenticates to GitHub. Chosen with GITHUB_DRIVER: "token" (default) or "app".
 *
 * @phpstan-type AccessibleRepository array{id: int, full_name: string, private: bool}
 * @phpstan-type Account array{key: string, label: string, repositories: list<AccessibleRepository>, error: string|null}
 */
abstract class GitHubProvider
{
    abstract public function configured(): bool;

    /**
     * Where an admin grants Atlas access to more repositories, if that happens outside Atlas.
     */
    public function installUrl(): ?string
    {
        return null;
    }

    /**
     * Get a client that can read the given repository.
     */
    abstract public function client(Repository $repository): PendingRequest;

    /**
     * List the accounts Atlas can read through, each with the repositories it can access.
     *
     * @return list<Account>
     */
    abstract public function accounts(): array;

    /**
     * Start using a repository the given account can access, or return null if it cannot.
     */
    abstract public function add(string $account, int $githubId): ?Repository;

    /**
     * Refresh each connected repository's name and drop repositories that lost access.
     */
    abstract public function sync(): void;

    /**
     * Run a search across the given repositories with as few requests as access allows.
     *
     * @param  Collection<int, Repository>  $repositories
     * @param  callable(PendingRequest, string): array<int, mixed>  $search  Receives a client and the " repo:a repo:b" qualifier.
     * @return list<mixed>
     */
    abstract public function searchAcross(Collection $repositories, callable $search): array;

    /**
     * @param  Collection<int, Repository>  $repositories
     */
    protected function qualifier(Collection $repositories): string
    {
        return $repositories->map(fn (Repository $repository) => " repo:{$repository->full_name}")->implode('');
    }

    /**
     * Apply GitHub's current view of the repositories to the connected ones.
     *
     * @param  iterable<Repository>  $connected
     * @param  Collection<int, AccessibleRepository>  $accessible
     */
    protected function reconcile(iterable $connected, Collection $accessible): void
    {
        $accessible = $accessible->keyBy('id');

        foreach ($connected as $repository) {
            isset($accessible[$repository->github_id])
                ? $repository->update(['full_name' => $accessible[$repository->github_id]['full_name']])
                : $repository->delete();
        }
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->acceptJson()
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(20);
    }

    /**
     * @param  array<string, string>  $query
     * @return Collection<int, array<string, mixed>>
     */
    protected function paginate(PendingRequest $client, string $url, ?string $key = null, array $query = []): Collection
    {
        $items = new Collection;

        for ($page = 1; $page <= 50; $page++) {
            $response = $client->get($url, [...$query, 'per_page' => 100, 'page' => $page])->throw();
            $batch = $key === null ? $response->json() : $response->json($key);

            $items->push(...$batch);

            if (count($batch) < 100) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $repositories
     * @return Collection<int, AccessibleRepository>
     */
    protected function summarise(Collection $repositories): Collection
    {
        return $repositories
            ->map(fn (array $repository) => [
                'id' => (int) $repository['id'],
                'full_name' => (string) $repository['full_name'],
                'private' => (bool) $repository['private'],
            ])
            ->sortBy('full_name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    /**
     * Describe an account, falling back to the repositories Atlas already uses if GitHub can't be reached.
     *
     * @param  callable(): Collection<int, AccessibleRepository>  $accessible
     * @param  Collection<int, Repository>  $connected
     * @return Account
     */
    protected function account(string $key, string $label, callable $accessible, Collection $connected): array
    {
        try {
            return ['key' => $key, 'label' => $label, 'repositories' => array_values($accessible()->all()), 'error' => null];
        } catch (RequestException $e) {
            report($e);

            return [
                'key' => $key,
                'label' => $label,
                'repositories' => array_values($connected->map(fn (Repository $repository) => [
                    'id' => $repository->github_id,
                    'full_name' => $repository->full_name,
                    'private' => false,
                ])->all()),
                'error' => "GitHub returned {$e->response->status()}: ".$e->response->json('message', 'unknown error'),
            ];
        }
    }
}
