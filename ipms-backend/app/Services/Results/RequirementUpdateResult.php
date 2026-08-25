<?php

namespace App\Services\Results;

use App\Models\Requirement;
use InvalidArgumentException;

final readonly class RequirementUpdateResult
{
    public const UPDATED = 'updated';

    public const RESUBMITTED = 'resubmitted';

    public function __construct(
        public Requirement $requirement,
        public string $action,
    ) {
        if (! in_array($action, [self::UPDATED, self::RESUBMITTED], true)) {
            throw new InvalidArgumentException("Unknown requirement update action [{$action}].");
        }
    }

    public static function updated(Requirement $requirement): self
    {
        return new self($requirement, self::UPDATED);
    }

    public static function resubmitted(Requirement $requirement): self
    {
        return new self($requirement, self::RESUBMITTED);
    }

    public function message(): string
    {
        return match ($this->action) {
            self::UPDATED => 'Requirement updated.',
            self::RESUBMITTED => 'Requirement resubmitted.',
        };
    }
}
