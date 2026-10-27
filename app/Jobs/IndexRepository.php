<?php

namespace App\Jobs;

use App\Enums\IndexStatus;
use App\Models\Repository;
use App\Services\Indexing\RepositoryIndexer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class IndexRepository implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    // Flex managed queue workers stop jobs after 90 seconds...
    public int $timeout = 80;

    public int $tries = 1;

    public function __construct(public Repository $repository) {}

    public function uniqueId(): string
    {
        return (string) $this->repository->id;
    }

    public function handle(RepositoryIndexer $indexer): void
    {
        $indexer->index($this->repository);

        EmbedChunks::dispatchMissing($this->repository);
    }

    public function failed(?Throwable $exception): void
    {
        // Keep serving the previous index, if any, but show why the refresh failed...
        $this->repository->forceFill([
            'index_files_total' => null,
            'index_files_done' => null,
            'index_status' => $this->repository->indexed_sha === null ? IndexStatus::Failed : IndexStatus::Indexed,
            'index_error' => Str::limit($exception?->getMessage() ?? 'Indexing failed.', 1000),
        ])->save();
    }
}
