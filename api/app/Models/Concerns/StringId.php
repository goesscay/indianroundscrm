<?php

namespace App\Models\Concerns;

/**
 * Serialises the auto-increment primary key as a string `id` (the old API used string ids).
 */
trait StringId
{
    public function initializeStringId(): void
    {
        $this->mergeCasts(['id' => 'string']);
    }
}
