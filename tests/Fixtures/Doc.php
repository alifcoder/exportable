<?php

declare(strict_types=1);

namespace Alif\Export\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doc extends Model
{
    protected $table = 'docs';

    protected $guarded = [];

    /** @return HasMany<Doc, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(Doc::class, 'doc_id');
    }

    /** @return HasMany<Doc, $this> */
    public function approvals(): HasMany
    {
        return $this->hasMany(Doc::class, 'approval_doc_id');
    }

    /** @return HasMany<Doc, $this> A constraint declared on the relation itself, which the job repeats on its own. */
    public function activeLines(): HasMany
    {
        return $this->hasMany(Doc::class, 'doc_id')->where('quantity', '>', 0);
    }
}
