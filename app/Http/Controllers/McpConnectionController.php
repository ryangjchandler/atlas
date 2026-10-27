<?php

namespace App\Http\Controllers;

use App\Enums\McpAuth;
use App\Models\McpConnection;
use App\Models\McpServer;
use App\Services\Mcp\McpOAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Mcp\Client\Exceptions\OAuthException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets each user connect their own account on OAuth-protected MCP servers.
 */
class McpConnectionController extends Controller
{
    public function __construct(protected McpOAuth $oauth) {}

    /**
     * Send the user to the server's authorization page.
     */
    public function connect(Request $request, McpServer $server): Response
    {
        abort_unless($server->auth === McpAuth::OAuth, 404);

        // Return to the page the user connected from, such as a new chat, but never to another site...
        $previous = url()->previous();
        $request->session()->put('mcp_oauth_return', str_starts_with($previous, url('/').'/') ? $previous : route('sources'));

        try {
            return Inertia::location($this->oauth->client($server)->redirect()->getTargetUrl());
        } catch (OAuthException $e) {
            return $this->failed($server, $e);
        }
    }

    /**
     * Exchange the authorization code for the user's tokens.
     */
    public function callback(Request $request, McpServer $server): RedirectResponse
    {
        abort_unless($server->auth === McpAuth::OAuth, 404);

        try {
            $token = $this->oauth->client($server)->exchangeCallback();
        } catch (OAuthException $e) {
            return $this->failed($server, $e);
        }

        McpConnection::firstOrNew(['user_id' => $request->user()->id, 'mcp_server_id' => $server->id])
            ->fillFromTokenSet($token)
            ->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Connected to {$server->name}."]);

        return $this->returnToOrigin();
    }

    public function destroy(Request $request, McpServer $server): RedirectResponse
    {
        $server->connections()->where('user_id', $request->user()->id)->delete();

        return back();
    }

    protected function failed(McpServer $server, OAuthException $e): RedirectResponse
    {
        report($e);

        Inertia::flash('toast', ['type' => 'error', 'message' => "Could not connect to {$server->name}: {$e->getMessage()}"]);

        return $this->returnToOrigin();
    }

    protected function returnToOrigin(): RedirectResponse
    {
        return redirect()->to(session()->pull('mcp_oauth_return', route('sources')));
    }
}
