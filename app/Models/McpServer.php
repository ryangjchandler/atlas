<?php

namespace App\Models;

use App\Enums\McpAuth;
use Database\Factories\McpServerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A remote MCP server (docs, support desk, etc.) connected to Atlas.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property McpAuth $auth
 * @property string|null $token
 * @property string|null $oauth_client_id
 * @property string|null $oauth_client_secret
 * @property string|null $oauth_scope
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'url', 'auth', 'token', 'oauth_client_id', 'oauth_client_secret', 'oauth_scope'])]
#[Hidden(['token', 'oauth_client_secret'])]
class McpServer extends Model
{
    /** @use HasFactory<McpServerFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'auth' => 'none',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auth' => McpAuth::class,
            'token' => 'encrypted',
            'oauth_client_secret' => 'encrypted',
        ];
    }

    /**
     * @return HasMany<McpConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(McpConnection::class);
    }
}
