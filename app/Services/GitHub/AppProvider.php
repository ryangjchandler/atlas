<?php

namespace App\Services\GitHub;

use App\Models\GitHubInstallation;
use App\Models\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads GitHub through Atlas's GitHub App, using a token per installation that only sees the repositories it was granted.
 */
class AppProvider extends GitHubProvider
{
    public function configured(): bool
    {
        return filled(config('services.github.app_id')) && filled(config('services.github.private_key'));
    }

    public function installUrl(): ?string
    {
        $slug = config('services.github.app_slug');

        return blank($slug) ? null : "https://github.com/apps/{$slug}/installations/new";
    }

    public function client(Repository $repository): PendingRequest
    {
        return $this->forInstallation($repository->installation ?? throw new RuntimeException("{$repository->full_name} is not connected through a GitHub App installation."));
    }

    public function accounts(): array
    {
        return array_values(GitHubInstallation::with('repositories')->orderBy('account_login')->get()
            ->map(fn (GitHubInstallation $installation) => $this->account(
                (string) $installation->id,
                $installation->account_login,
                fn () => $this->accessibleRepositories($installation),
                $installation->repositories,
            ))
            ->all());
    }

    public function add(string $account, int $githubId): ?Repository
    {
        $installation = GitHubInstallation::find((int) $account);
        $granted = $installation === null ? null : $this->accessibleRepositories($installation)->firstWhere('id', $githubId);

        return $granted === null ? null : $installation->repositories()->firstOrCreate(
            ['github_id' => $granted['id']],
            ['full_name' => $granted['full_name']],
        );
    }

    /**
     * Mirror the app's installations, then reconcile each installation's connected repositories.
     */
    public function sync(): void
    {
        $installations = $this->paginate($this->asApp(), '/app/installations');

        DB::transaction(function () use ($installations) {
            foreach ($installations as $installation) {
                GitHubInstallation::updateOrCreate(['github_id' => $installation['id']], [
                    'account_login' => $installation['account']['login'],
                    'account_type' => $installation['account']['type'],
                ]);
            }

            GitHubInstallation::whereNotIn('github_id', $installations->pluck('id'))->delete();
        });

        foreach (GitHubInstallation::has('repositories')->get() as $installation) {
            $this->reconcile($installation->repositories, $this->accessibleRepositories($installation));
        }
    }

    /**
     * Run a search per installation, since an installation token can only search the repositories it was granted.
     */
    public function searchAcross(Collection $repositories, callable $search): array
    {
        $results = [];

        foreach ($repositories->groupBy('github_installation_id') as $group) {
            array_push($results, ...$search($this->client($group->first()), $this->qualifier($group)));
        }

        return $results;
    }

    /**
     * Get a client authenticated as an installation, which can read the repositories it was granted.
     */
    public function forInstallation(GitHubInstallation $installation): PendingRequest
    {
        // Installation tokens last an hour; refresh well before they expire...
        $token = Cache::remember("github:installation-token:{$installation->github_id}", now()->addMinutes(50), fn () => $this->asApp()
            ->post("/app/installations/{$installation->github_id}/access_tokens")
            ->throw()
            ->json('token'));

        return $this->request()->withToken($token);
    }

    /**
     * @return Collection<int, array{id: int, full_name: string, private: bool}>
     */
    protected function accessibleRepositories(GitHubInstallation $installation): Collection
    {
        return $this->summarise($this->paginate($this->forInstallation($installation), '/installation/repositories', 'repositories'));
    }

    /**
     * Get a client authenticated as the app itself, for managing installations.
     */
    protected function asApp(): PendingRequest
    {
        return $this->request()->withToken($this->appJwt());
    }

    /**
     * Create the short-lived RS256 JWT that authenticates as the app.
     */
    protected function appJwt(): string
    {
        if (! $this->configured()) {
            throw new RuntimeException('The GitHub App is not configured. Set GITHUB_APP_ID and GITHUB_APP_PRIVATE_KEY.');
        }

        $encode = fn (string $data) => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        // Backdate the issue time to allow for clock drift, as GitHub recommends...
        $unsigned = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$encode((string) json_encode([
            'iat' => now()->subMinute()->getTimestamp(),
            'exp' => now()->addMinutes(9)->getTimestamp(),
            'iss' => (string) config('services.github.app_id'),
        ]));

        $key = openssl_pkey_get_private(str_replace('\n', "\n", (string) config('services.github.private_key')));

        if ($key === false || ! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The GitHub App private key could not be used to sign a token.');
        }

        return $unsigned.'.'.$encode($signature);
    }
}
