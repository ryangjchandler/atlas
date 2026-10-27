<?php

namespace App\Models;

use App\Enums\IndexStatus;
use App\Services\GitHub\GitHubProvider;
use Database\Factories\RepositoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;

/**
 * A GitHub repository that Atlas has been told to use.
 *
 * @property int $id
 * @property int|null $github_installation_id
 * @property int $github_id
 * @property string $full_name
 * @property string|null $default_branch
 * @property IndexStatus $index_status
 * @property string|null $indexed_sha
 * @property int|null $index_files_total Changed files being indexed, once known.
 * @property int|null $index_files_done
 * @property Carbon|null $indexed_at
 * @property string|null $index_error
 * @property string|null $overview
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['github_installation_id', 'github_id', 'full_name'])]
class Repository extends Model
{
    /** @use HasFactory<RepositoryFactory> */
    use HasFactory;

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'index_status' => 'pending',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'index_status' => IndexStatus::class,
            'indexed_at' => 'datetime',
        ];
    }

    /**
     * The GitHub App installation the repository is read through, when using the "app" driver.
     *
     * @return BelongsTo<GitHubInstallation, $this>
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(GitHubInstallation::class, 'github_installation_id');
    }

    /**
     * Get a GitHub client that can read this repository.
     */
    public function github(): PendingRequest
    {
        return app(GitHubProvider::class)->client($this);
    }

    /**
     * @return HasMany<CodeChunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(CodeChunk::class);
    }
}
