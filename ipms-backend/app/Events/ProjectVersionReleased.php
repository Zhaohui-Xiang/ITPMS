<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ProjectVersionReleased
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $projectVersionId,
        public readonly int $projectId,
        public readonly int $releasedById,
        public readonly bool $isOverride,
    ) {}
}
