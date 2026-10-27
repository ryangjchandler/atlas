<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A run of lines from one file of an indexed repository.
 *
 * @property int $id
 * @property int $repository_id
 * @property string $path
 * @property string $blob_sha
 * @property string $commit_sha
 * @property int $start_line
 * @property int $end_line
 * @property string $content
 * @property array<int, float>|null $embedding
 */
#[Hidden(['search', 'embedding'])]
class CodeChunk extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Repository, $this>
     */
    public function repository(): BelongsTo
    {
        return $this->belongsTo(Repository::class);
    }

    /**
     * Get the GitHub URL of the chunk's lines at the commit they were read from.
     */
    public function permalink(): string
    {
        return "https://github.com/{$this->repository->full_name}/blob/{$this->commit_sha}/{$this->path}#L{$this->start_line}-L{$this->end_line}";
    }
}
