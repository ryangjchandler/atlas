<?php

namespace App\Ai\Tools;

use App\Services\GitHub\GitHubProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchGitHubCode extends GitHubTool
{
    public function description(): Stringable|string
    {
        return 'Search source code in the connected GitHub repositories ('.$this->repositoryList().'). '
            .'Returns matching file paths with links. Use GitHub code search syntax, e.g. "createInvoice language:php".';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $results = app(GitHubProvider::class)->searchAcross($this->repositories, fn (PendingRequest $github, string $qualifier) => $github
                ->get('/search/code', ['q' => $request->string('query').$qualifier, 'per_page' => 20])
                ->throw()
                ->json('items'));
        } catch (RequestException $e) {
            return "GitHub code search failed ({$e->response->status()}): ".$e->response->json('message', 'unknown error');
        }

        $items = collect($results)->map(fn (array $item) => [
            'repository' => $item['repository']['full_name'],
            'path' => $item['path'],
            'url' => $item['html_url'],
        ]);

        return $items->isEmpty() ? 'No matching code found.' : $items->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The code search query, without repo: qualifiers.')->required(),
        ];
    }
}
