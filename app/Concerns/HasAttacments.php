<?php

namespace App\Concerns;

use App\Models\Addons\Attachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasAttacments
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
