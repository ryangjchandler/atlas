<?php

namespace App\Services\Mcp;

use App\Models\McpConnection;
use App\Models\McpServer;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Mcp\Client;
use Laravel\Mcp\Client\OAuth\OAuthClient;

/**
 * Connects users to OAuth-protected MCP servers and keeps their access tokens fresh.
 */
class McpOAuth
{
    /**
     * Get the OAuth client for a server, which handles discovery, dynamic registration, and PKCE.
     */
    public function client(McpServer $server): OAuthClient
    {
        return Client::web($server->url)
            ->withOAuth(
                clientId: $server->oauth_client_id,
                clientSecret: $server->oauth_client_secret,
                scope: $server->oauth_scope,
                redirectUri: route('mcp-servers.oauth.callback', $server),
            )
            ->oAuthClient();
    }

    /**
     * Get a usable access token for the user, refreshing it when it has expired or is about to.
     */
    public function accessToken(McpServer $server, User $user): ?string
    {
        $connection = $server->connections()->where('user_id', $user->id)->first();

        if ($connection === null || ! $this->expiresSoon($connection)) {
            return $connection?->access_token;
        }

        if ($connection->refresh_token === null) {
            return null;
        }

        // Refresh tokens are often single-use, so only one request may refresh at a time...
        return Cache::lock("mcp-connection-refresh:{$connection->id}", 30)->block(15, function () use ($server, $connection) {
            $connection->refresh();

            if (! $this->expiresSoon($connection)) {
                return $connection->access_token;
            }

            $token = $this->client($server)->refreshCredentials(
                (string) $connection->refresh_token,
                $connection->client_id,
                $connection->client_secret,
            );

            $connection->fillFromTokenSet($token)->save();

            return $connection->access_token;
        });
    }

    protected function expiresSoon(McpConnection $connection): bool
    {
        return $connection->expires_at !== null && $connection->expires_at->subMinute()->isPast();
    }
}
