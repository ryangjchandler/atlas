<?php

namespace App\Ai\Tools;

use App\Models\CodeChunk;
use App\Models\Repository;
use App\Services\Indexing\SearchTerms;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Searches the pre-built index of the connected repositories, so most questions need no live GitHub calls.
 */
class SearchIndex implements Tool
{
    protected const LIMIT = 8;

    public function description(): Stringable|string
    {
        return 'Search the indexed source code of the connected repositories. This is fast and should be your first step. '
            .'Returns the matching code with file paths, line numbers, and permalinks to the commit each match was read from. '
            .'Query with identifiers (class, function, route, config key) and/or plain-language descriptions of behaviour.';
    }

    public function handle(Request $request): Stringable|string
    {
        $query = $request->string('query')->toString();

        $chunks = $this->search($query, $request->string('repository')->toString() ?: null);

        if ($chunks->isEmpty()) {
            return 'No indexed code matched. Try different identifiers or wording, or fall back to the live GitHub tools.';
        }

        return $chunks->map(fn (CodeChunk $chunk) => sprintf(
            "### %s — %s (lines %d-%d)\n%s\n```\n%s\n```",
            $chunk->repository->full_name,
            $chunk->path,
            $chunk->start_line,
            $chunk->end_line,
            $chunk->permalink(),
            $chunk->content,
        ))->implode("\n\n");
    }

    /**
     * Rank chunks by full-text match, blended with vector similarity when embeddings are available.
     *
     * @return Collection<int, CodeChunk>
     */
    public function search(string $query, ?string $repository = null): Collection
    {
        $tsQuery = SearchTerms::toTsQuery($query);
        $allWords = SearchTerms::toAllWordsTsQuery($query);
        $vector = $this->embed($query);

        if ($tsQuery === null && $vector === null) {
            return new Collection;
        }

        // Paths and identifiers are indexed unstemmed, prose is stemmed, so match the query both ways...
        // Chunks containing every query word (such as an exact identifier) rank above those matching only some...
        $text = $tsQuery === null ? '0' : "ts_rank_cd(search, (to_tsquery('simple', ?) || to_tsquery('english', ?)), 32)"
            ." + (search @@ (to_tsquery('simple', ?) || to_tsquery('english', ?)))::int";
        $similarity = $vector === null ? '0' : '(1 - (embedding <=> ?::vector))';

        return CodeChunk::query()
            ->with('repository')
            ->select('code_chunks.*')
            ->selectRaw("{$text} + {$similarity} as score", array_values(array_filter([$tsQuery, $tsQuery, $allWords, $allWords, $vector === null ? null : json_encode($vector)])))
            ->when($repository, fn ($q) => $q->whereIn('repository_id', Repository::where('full_name', $repository)->select('id')))
            ->where(function ($q) use ($tsQuery, $vector) {
                if ($tsQuery !== null) {
                    $q->orWhereRaw("search @@ (to_tsquery('simple', ?) || to_tsquery('english', ?))", [$tsQuery, $tsQuery]);
                }

                if ($vector !== null) {
                    $q->orWhereRaw('embedding <=> ?::vector < 0.6', [json_encode($vector)]);
                }
            })
            ->orderByDesc('score')
            ->limit(self::LIMIT)
            ->get();
    }

    /**
     * @return array<int, float>|null
     */
    protected function embed(string $query): ?array
    {
        $provider = config('atlas.index.embeddings.provider');

        if (blank($provider) || ! DB::table('code_chunks')->whereNotNull('embedding')->exists()) {
            return null;
        }

        try {
            return Embeddings::for([$query])
                ->dimensions(config('atlas.index.embeddings.dimensions'))
                ->cache()
                ->generate($provider, config('atlas.index.embeddings.model'))
                ->first();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Identifiers and/or a description of the behaviour you are looking for.')->required(),
            'repository' => $schema->string()->description('Optional repository full name ("owner/name") to limit the search to.'),
        ];
    }
}
