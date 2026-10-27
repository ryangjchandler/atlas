<?php

namespace App\Http\Controllers;

use App\Enums\McpAuth;
use App\Models\McpServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class McpServerController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        McpServer::create($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'max:2048', $request->input('auth') === McpAuth::OAuth->value ? 'url:https' : 'url:https,http'],
            'auth' => ['required', Rule::enum(McpAuth::class)],
            'token' => ['exclude_unless:auth,token', 'required', 'string', 'max:4096'],
            'oauth_client_id' => ['exclude_unless:auth,oauth', 'nullable', 'string', 'max:255'],
            'oauth_client_secret' => ['exclude_unless:auth,oauth', 'nullable', 'string', 'max:4096'],
            'oauth_scope' => ['exclude_unless:auth,oauth', 'nullable', 'string', 'max:1000'],
        ], [
            'url.url' => $request->input('auth') === McpAuth::OAuth->value ? 'OAuth servers must use https.' : 'Enter a valid URL.',
        ]));

        return back();
    }

    public function destroy(McpServer $mcpServer): RedirectResponse
    {
        $mcpServer->delete();

        return back();
    }
}
