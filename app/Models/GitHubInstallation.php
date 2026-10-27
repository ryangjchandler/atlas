<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An installation of Atlas's GitHub App on a user or organisation account.
 *
 * @property int $id
 * @property int $github_id
 * @property string $account_login
 * @property string $account_type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['github_id', 'account_login', 'account_type'])]
class GitHubInstallation extends Model
{
    protected $table = 'github_installations';

    /**
     * @return HasMany<Repository, $this>
     */
    public function repositories(): HasMany
    {
        return $this->hasMany(Repository::class, 'github_installation_id');
    }
}
