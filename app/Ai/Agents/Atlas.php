<?php

namespace App\Ai\Agents;

use App\Ai\Tools\ReadGitHubPath;
use App\Ai\Tools\SearchGitHubCode;
use App\Ai\Tools\SearchGitHubIssues;
use App\Ai\Tools\SearchIndex;
use App\Enums\McpAuth;
use App\Models\McpServer;
use App\Models\Repository;
use App\Models\User;
use App\Services\Mcp\McpOAuth;
use Illuminate\Database\Eloquent\Collection;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\Primitives\Tool as McpTool;
use Stringable;
use Throwable;

/**
 * Answers questions about the product using only facts found in the sources connected to Atlas.
 */
#[MaxSteps(25)]
#[Timeout(120)]
class Atlas implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /** @var Collection<int, Repository> */
    protected Collection $repositories;

    /** @var Collection<int, McpServer> */
    protected Collection $mcpServers;

    /**
     * Bearer tokens for each usable MCP server, keyed by server id; null means no token is needed.
     *
     * @var array<int, string|null>|null
     */
    protected ?array $mcpCredentials = null;

    /**
     * @param  User|null  $user  The person asking, whose own connections are used for OAuth-protected MCP servers.
     */
    public function __construct(public ?User $user = null)
    {
        $this->repositories = Repository::with('installation')->orderBy('full_name')->get();
        $this->mcpServers = McpServer::orderBy('name')->get();
    }

    public function instructions(): Stringable|string
    {
        $repositories = $this->repositories->map(fn (Repository $repository) => $this->describe($repository))->implode("\n\n") ?: 'none';
        $credentials = $this->mcpCredentials();
        $servers = $this->mcpServers
            ->map(fn (McpServer $server) => array_key_exists($server->id, $credentials)
                ? "- {$server->name}"
                : "- {$server->name} (unavailable: the user has not connected their account; tell them to connect it on the Sources page if it is relevant)")
            ->implode("\n") ?: 'none';

        return <<<PROMPT
        You are Atlas, a research assistant for an engineering organisation's products.

        People such as support engineers ask you how the product behaves. Your answers must be grounded in facts
        you have verified with your tools during this conversation.

        Connected MCP servers:
        {$servers}

        Connected GitHub repositories:
        {$repositories}

        Rules:
        - Start with search_index: it searches a pre-built index of the repositories and is much faster than the live GitHub tools.
          Run several targeted searches (identifiers and plain-language phrasings) rather than one broad one.
        - Use the live GitHub tools only for issues and pull requests, for files the index lacks, or when the index may be stale.
        - Investigate before answering, and check docs and other sources when they are relevant.
        - Prefer the source code as the ground truth for behavior; use docs and issues for intent and known problems.
        - Cite every factual claim with a link to the file, doc page, or issue it came from.
        - If the sources do not answer the question, say so plainly and describe what you checked. Never guess.
        - Distinguish what you verified from what you infer, and label inferences.
        - Lead with the direct answer, then the supporting evidence.
        PROMPT;
    }

    /**
     * @return list<Tool|McpTool>
     */
    public function tools(): iterable
    {
        $tools = $this->repositories->isEmpty() ? [] : [
            new SearchIndex,
            new SearchGitHubCode($this->repositories),
            new ReadGitHubPath($this->repositories),
            new SearchGitHubIssues($this->repositories),
        ];

        foreach ($this->mcpServers as $server) {
            array_push($tools, ...$this->mcpTools($server));
        }

        return $tools;
    }

    /**
     * Describe a repository for the system prompt, including its index overview when it has one.
     */
    protected function describe(Repository $repository): string
    {
        if ($repository->indexed_sha === null) {
            return "- {$repository->full_name} (not indexed yet: use the live GitHub tools)";
        }

        return "- {$repository->full_name} (indexed at commit {$repository->indexed_sha} on {$repository->default_branch})\n"
            .preg_replace('/^/m', '  ', (string) $repository->overview);
    }

    /**
     * Resolve how to authenticate to each MCP server for this user, leaving out servers they cannot use.
     *
     * @return array<int, string|null>
     */
    protected function mcpCredentials(): array
    {
        if ($this->mcpCredentials !== null) {
            return $this->mcpCredentials;
        }

        $this->mcpCredentials = [];

        foreach ($this->mcpServers as $server) {
            $token = match ($server->auth) {
                McpAuth::None => null,
                McpAuth::Token => $server->token,
                McpAuth::OAuth => $this->oauthToken($server),
            };

            if ($server->auth === McpAuth::None || $token !== null) {
                $this->mcpCredentials[$server->id] = $token;
            }
        }

        return $this->mcpCredentials;
    }

    protected function oauthToken(McpServer $server): ?string
    {
        if ($this->user === null) {
            return null;
        }

        try {
            return app(McpOAuth::class)->accessToken($server, $this->user);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Get the tools exposed by an MCP server, skipping servers that cannot be reached.
     *
     * @return list<McpTool>
     */
    protected function mcpTools(McpServer $server): array
    {
        $credentials = $this->mcpCredentials();

        if (! array_key_exists($server->id, $credentials)) {
            return [];
        }

        try {
            $client = Client::web($server->url);

            if ($credentials[$server->id] !== null) {
                $client->withToken($credentials[$server->id]);
            }

            return array_values($client->tools()->all());
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }
}
