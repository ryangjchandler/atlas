<?php

use App\Jobs\IndexRepository;
use App\Models\Repository;
use App\Services\GitHub\GitHubProvider;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Pick up repository renames and revoked access, then keep each index close to its default branch.
// Unchanged repositories are a quick no-op.
Schedule::call(function () {
    $github = app(GitHubProvider::class);

    if ($github->configured()) {
        $github->sync();
    }

    Repository::each(fn (Repository $repository) => IndexRepository::dispatch($repository));
})->name('index-repositories')->everyFifteenMinutes()->withoutOverlapping();
