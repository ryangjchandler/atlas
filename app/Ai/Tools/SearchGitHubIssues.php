<?php

namespace App\Ai\Tools;

use App\Services\GitHub\GitHubProvider;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class SearchGitHubIssues extends GitHubTool
{
    public function description(): Stringable|string
    {
        return 'Search issues and pull requests in the connected GitHub repositories ('.$this->repositoryList().'). '
            .'Useful for known bugs, recent changes, and the reasoning behind decisions. Supports qualifiers such as "is:pr is:merged".';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $results = app(GitHubProvider::class)->searchAcross($this->repositories, fn (PendingRequest $github, string $qualifier) => $github
                ->get('/search/issues', ['q' => $request->string('query').$qualifier, 'per_page' => 10])
                ->throw()
                ->json('items'));
        } catch (RequestException $e) {
            return "GitHub issue search failed ({$e->response->status()}): ".$e->response->json('message', 'unknown error');
        }

        $items = collect($results)->map(fn (array $item) => [
            'title' => $item['title'],
            'type' => isset($item['pull_request']) ? 'pull_request' : 'issue',
            'state' => $item['state'],
            'url' => $item['html_url'],
            'updated_at' => $item['updated_at'],
            'body' => Str::limit((string) $item['body'], 1500),
        ]);

        return $items->isEmpty() ? 'No matching issues or pull requests found.' : $items->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('The search query, without repo: qualifiers.')->required(),
        ];
    }
}
