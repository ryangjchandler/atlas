<?php

namespace App\Http\Controllers;

use App\Models\McpServer;
use App\Models\Repository;
use App\Services\GitHub\GitHubProvider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SourceController extends Controller
{
    /**
     * Show the repositories and MCP servers this Atlas installation answers from.
     */
    public function index(Request $request, GitHubProvider $github): Response
    {
        return Inertia::render('sources', [
            'github' => [
                'driver' => config('services.github.driver') === 'app' ? 'app' : 'token',
                'configured' => $github->configured(),
                'installUrl' => $github->installUrl(),
            ],
            'repositories' => fn () => Repository::orderBy('full_name')
                ->withCount(['chunks', 'chunks as embedded_chunks_count' => fn ($query) => $query->whereNotNull('embedding')])
                ->get([
                    'id', 'github_id', 'full_name', 'default_branch', 'index_status', 'indexed_sha', 'indexed_at', 'index_error',
                    'index_files_total', 'index_files_done',
                ]),
            'embeddings' => filled(config('atlas.index.embeddings.provider')),
            // Evaluated only when requested, so polling index statuses does not call GitHub...
            'accounts' => fn () => $request->user()->cannot('admin') || ! $github->configured() ? [] : $github->accounts(),
            'mcpServers' => McpServer::orderBy('name')
                ->withExists(['connections as connected' => fn ($query) => $query->where('user_id', $request->user()->id)])
                ->get(['id', 'name', 'url', 'auth']),
        ]);
    }
}
