<?php

namespace App\Ai\Tools;

use App\Models\Repository;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\Tool;

/**
 * Base for tools that read live from the GitHub repositories connected to Atlas.
 */
abstract class GitHubTool implements Tool
{
    /**
     * @param  Collection<int, Repository>  $repositories
     */
    public function __construct(protected Collection $repositories) {}

    protected function find(string $fullName): ?Repository
    {
        return $this->repositories->first(fn (Repository $repository) => strcasecmp($repository->full_name, $fullName) === 0);
    }

    protected function repositoryList(): string
    {
        return $this->repositories->pluck('full_name')->implode(', ');
    }
}
