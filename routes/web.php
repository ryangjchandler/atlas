<?php

use App\Http\Controllers\ConversationController;
use App\Http\Controllers\GitHubInstallationController;
use App\Http\Controllers\McpConnectionController;
use App\Http\Controllers\McpServerController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\RepositoryController;
use App\Http\Controllers\SourceController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::redirect('dashboard', 'chat')->name('dashboard');

    Route::get('chat/{conversation?}', [ConversationController::class, 'show'])->name('chat');
    Route::post('chat', [ConversationController::class, 'stream'])->name('chat.stream');
    Route::post('onboarding/welcome', [OnboardingController::class, 'dismissWelcome'])->name('onboarding.welcome');
    Route::post('onboarding/source-hint', [OnboardingController::class, 'dismissSourceHint'])->name('onboarding.source-hint');

    Route::get('sources', [SourceController::class, 'index'])->name('sources');
    Route::get('sources/mcp-servers/{server}/connect', [McpConnectionController::class, 'connect'])->name('mcp-servers.oauth.connect');
    Route::get('sources/mcp-servers/{server}/callback', [McpConnectionController::class, 'callback'])->name('mcp-servers.oauth.callback');
    Route::delete('sources/mcp-servers/{server}/connection', [McpConnectionController::class, 'destroy'])->name('mcp-servers.oauth.disconnect');

    // Configuring what Atlas can read is limited to admins...
    Route::middleware('can:admin')->group(function () {
        Route::get('sources/github/callback', [GitHubInstallationController::class, 'sync'])->name('github.callback');
        Route::post('sources/github/sync', [GitHubInstallationController::class, 'sync'])->name('github.sync');
        Route::post('sources/repositories', [RepositoryController::class, 'store'])->name('repositories.store');
        Route::post('sources/repositories/{repository}/reindex', [RepositoryController::class, 'reindex'])->name('repositories.reindex');
        Route::delete('sources/repositories/{repository}', [RepositoryController::class, 'destroy'])->name('repositories.destroy');
        Route::post('sources/mcp-servers', [McpServerController::class, 'store'])->name('mcp-servers.store');
        Route::delete('sources/mcp-servers/{mcpServer}', [McpServerController::class, 'destroy'])->name('mcp-servers.destroy');

        Route::get('settings/users', [UserController::class, 'index'])->name('users.index');
        Route::patch('settings/users/{user}', [UserController::class, 'update'])->name('users.update');
    });
});

require __DIR__.'/settings.php';
