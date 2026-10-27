<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Client\OAuth\TokenSet;

/**
 * One user's OAuth authorization for an MCP server.
 *
 * @property int $id
 * @property int $user_id
 * @property int $mcp_server_id
 * @property string $access_token
 * @property string|null $refresh_token
 * @property Carbon|null $expires_at
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'mcp_server_id', 'access_token', 'refresh_token', 'expires_at', 'client_id', 'client_secret'])]
#[Hidden(['access_token', 'refresh_token', 'client_secret'])]
class McpConnection extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'client_secret' => 'encrypted',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Store a token set, keeping the previous refresh token and client when the server does not send new ones.
     */
    public function fillFromTokenSet(TokenSet $token): static
    {
        return $this->fill([
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken ?? $this->refresh_token,
            'expires_at' => $token->expiresAt === null ? null : Carbon::createFromTimestamp($token->expiresAt),
            'client_id' => $token->clientId ?? $this->client_id,
            'client_secret' => $token->clientSecret ?? $this->client_secret,
        ]);
    }

    /**
     * @return BelongsTo<McpServer, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(McpServer::class, 'mcp_server_id');
    }
}
