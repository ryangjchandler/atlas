<?php

namespace App\Http\Controllers;

use App\Services\GitHub\GitHubProvider;
use Illuminate\Http\RedirectResponse;

class GitHubInstallationController extends Controller
{
    /**
     * Refresh repository access, e.g. after the GitHub App is installed or a token's access changes.
     *
     * GitHub redirects here (the app's Setup URL) with an installation_id, which is ignored:
     * installations are always read from the app's own API so a crafted URL cannot add one.
     */
    public function sync(GitHubProvider $github): RedirectResponse
    {
        $github->sync();

        return to_route('sources');
    }
}
