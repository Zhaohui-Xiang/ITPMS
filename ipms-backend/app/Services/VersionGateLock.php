<?php

namespace App\Services;

use App\Exceptions\DomainConflictException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class VersionGateLock
{
    public const MUTATION_LOCKED_SQLSTATE = 'IV001';

    public function acquire(int $projectVersionId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Version gate locks require an active database transaction.');
        }

        DB::select(
            <<<'SQL'
                SELECT pg_advisory_xact_lock(
                    hashtextextended('itpms:project-version:' || ?::text, 0)
                )
                SQL,
            [$projectVersionId],
        );
    }

    public static function mutationConflict(QueryException $exception): ?DomainConflictException
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        if ($sqlState !== self::MUTATION_LOCKED_SQLSTATE) {
            return null;
        }

        $detail = $exception->errorInfo[2] ?? $exception->getMessage();
        preg_match('/project_version_id=(\d+),status=(\d+)/', $detail, $matches);
        $versionId = isset($matches[1]) ? (int) $matches[1] : null;
        $status = isset($matches[2]) ? (int) $matches[2] : null;

        return new DomainConflictException(
            'VERSION_LOCKED',
            errors: [
                'project_version_id' => $versionId === null ? [] : [$versionId],
                'status' => ['current' => $status],
            ],
            message: 'The project version no longer accepts task or defect mutations.',
        );
    }
}
