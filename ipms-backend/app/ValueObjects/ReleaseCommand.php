<?php

namespace App\ValueObjects;

use App\Exceptions\DomainConflictException;

final readonly class ReleaseCommand
{
    private function __construct(
        public int $lockVersion,
        public bool $force,
        public ?string $releaseNotes,
        public bool $hasReleaseNotes,
        public ?string $forceReason,
    ) {}

    public static function from(array $data): self
    {
        $errors = [];
        $lockVersion = $data['lock_version'] ?? null;
        $force = $data['force'] ?? false;
        $releaseNotes = $data['release_notes'] ?? null;
        $forceReason = $data['force_reason'] ?? null;

        if (! is_int($lockVersion) || $lockVersion < 1) {
            $errors['lock_version'] = ['The lock version must be a positive integer.'];
        }

        if (! is_bool($force)) {
            $errors['force'] = ['The force field must be true or false.'];
        }

        if ($releaseNotes !== null && ! is_string($releaseNotes)) {
            $errors['release_notes'] = ['The release notes must be a string or null.'];
        }

        if ($forceReason !== null && ! is_string($forceReason)) {
            $errors['force_reason'] = ['The force reason must be a string or null.'];
        }

        if ($errors !== []) {
            throw new DomainConflictException(
                'INVALID_RELEASE_COMMAND',
                422,
                $errors,
                'The release command is invalid.',
            );
        }

        return new self(
            $lockVersion,
            $force,
            $releaseNotes === null ? null : trim($releaseNotes),
            array_key_exists('release_notes', $data),
            $forceReason === null ? null : trim($forceReason),
        );
    }
}
