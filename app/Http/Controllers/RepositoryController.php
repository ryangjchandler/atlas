<?php

namespace App\Http\Controllers;

use App\Jobs\IndexRepository;
use App\Models\Repository;
use App\Services\GitHub\GitHubProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RepositoryController extends Controller
{
    /**
     * Let Atlas use one of the repositories its GitHub account can access.
     */
    public function store(Request $request, GitHubProvider $github): RedirectResponse
    {
        $request->validate([
            'account' => ['required', 'string'],
            'github_id' => ['required', 'integer'],
        ]);

        $repository = $github->add($request->string('account'), $request->integer('github_id'))
            ?? throw ValidationException::withMessages(['github_id' => 'Atlas does not have access to that repository on GitHub.']);

        if ($repository->wasRecentlyCreated) {
            IndexRepository::dispatch($repository);
        }

        return back();
    }

    /**
     * Refresh the repository's index from its latest commit.
     */
    public function reindex(Repository $repository): RedirectResponse
    {
        IndexRepository::dispatch($repository);

        return back();
    }

    public function destroy(Repository $repository): RedirectResponse
    {
        $repository->delete();

        return back();
    }
}
