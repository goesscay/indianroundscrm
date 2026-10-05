<?php

namespace App\Services\Mail;

use Closure;

final class AttachmentData
{
    /**
     * @param  Closure():(?string)|null  $loader  returns the raw bytes (called only for attachments we decide to keep)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $contentType,
        public readonly int $size,
        public readonly bool $isFile = true,
        public readonly bool $isInline = false,
        private readonly ?Closure $loader = null,
        private ?string $content = null,
    ) {
    }

    public function bytes(): ?string
    {
        if ($this->content === null && $this->loader) {
            $this->content = ($this->loader)();
        }

        return $this->content;
    }
}
