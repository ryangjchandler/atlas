<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class ReadGitHubPath extends GitHubTool
{
    /**
     * The most file content returned to the model, in characters.
     */
    protected const MAX_LENGTH = 40_000;

    public function description(): Stringable|string
    {
        return 'Read a file, or list a directory, in one of the connected GitHub repositories ('.$this->repositoryList().'). '
            .'Use an empty path to list the repository root.';
    }

    public function handle(Request $request): Stringable|string
    {
        $name = $request->string('repository')->toString();
        $repository = $this->find($name);

        if ($repository === null) {
            return "Repository [{$name}] is not connected to Atlas. Connected repositories: {$this->repositoryList()}.";
        }

        $path = trim($request->string('path')->toString(), '/');

        $response = $repository->github()->get("/repos/{$repository->full_name}/contents/{$path}", array_filter([
            'ref' => $request->string('ref')->toString() ?: null,
        ]));

        if ($response->failed()) {
            return "GitHub request failed ({$response->status()}): ".$response->json('message', 'unknown error');
        }

        $data = $response->json();

        // A directory comes back as a list of entries...
        if (array_is_list($data)) {
            return collect($data)
                ->map(fn (array $entry) => ($entry['type'] === 'dir' ? '[dir]  ' : '[file] ').$entry['path'])
                ->implode("\n");
        }

        if (($data['type'] ?? null) !== 'file' || ($data['encoding'] ?? null) !== 'base64') {
            return "[{$path}] is not a readable text file. See {$data['html_url']}";
        }

        $content = (string) base64_decode($data['content']);

        return "Source: {$data['html_url']}\n\n".Str::limit($content, self::MAX_LENGTH, "\n… (truncated)");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository' => $schema->string()->description('Repository full name, e.g. "acme/api".')->required(),
            'path' => $schema->string()->description('Path within the repository; empty for the root.')->required(),
            'ref' => $schema->string()->description('Optional branch, tag, or commit SHA.'),
        ];
    }
}
