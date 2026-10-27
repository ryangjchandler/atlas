<?php

namespace App\Jobs;

use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

/**
 * Add vectors to a small batch of chunks, so each job stays well inside a short worker runtime.
 */
class EmbedChunks implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    protected const BATCH = 100;

    public int $timeout = 80;

    public int $tries = 3;

    public int $backoff = 30;

    /**
     * @param  list<int>  $ids
     */
    public function __construct(public array $ids) {}

    /**
     * Queue jobs for every chunk of the repository that has no vector yet, which also catches up after failures.
     */
    public static function dispatchMissing(Repository $repository): void
    {
        if (blank(config('atlas.index.embeddings.provider'))) {
            return;
        }

        $ids = array_map(intval(...), $repository->chunks()->whereNull('embedding')->orderBy('id')->pluck('id')->all());

        foreach (array_chunk($ids, self::BATCH) as $batch) {
            static::dispatch($batch);
        }
    }

    public function uniqueId(): string
    {
        return (string) $this->ids[0];
    }

    public function handle(): void
    {
        // Chunks may have been replaced or embedded since this job was queued...
        $chunks = DB::table('code_chunks')->whereIn('id', $this->ids)->whereNull('embedding')->orderBy('id')->get(['id', 'path', 'content']);

        if ($chunks->isEmpty()) {
            return;
        }

        $response = Embeddings::for($chunks->map(fn (object $chunk) => "{$chunk->path}\n\n{$chunk->content}")->all())
            ->dimensions(config('atlas.index.embeddings.dimensions'))
            ->generate(config('atlas.index.embeddings.provider'), config('atlas.index.embeddings.model'));

        DB::transaction(function () use ($chunks, $response) {
            foreach ($chunks->values() as $position => $chunk) {
                DB::update('update code_chunks set embedding = ?::vector where id = ?', [json_encode($response->embeddings[$position]), $chunk->id]);
            }
        });
    }
}
