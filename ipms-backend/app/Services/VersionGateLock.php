<?php

namespace App\Services;

use App\Exceptions\DomainConflictException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class VersionGateLock
{
    public const MUTATION_LOCKED_SQLSTATE = 'IV001';

    public function acquireScope(int $requirementId, int $projectId): void
    {
        $this->assertTransaction();

        DB::select(
            <<<'SQL'
                SELECT pg_advisory_xact_lock(
                    hashtextextended(
                        'itpms:requirement-project:' || ?::text || ':' || ?::text,
                        0
                    )
                )
                SQL,
            [$requirementId, $projectId],
        );
    }

    /**
     * @param  iterable<array{requirement_id: int, project_id: int}>  $scopes
     */
    public function acquireScopes(iterable $scopes): void
    {
        collect($scopes)
            ->map(static fn (array $scope): array => [
                'requirement_id' => (int) $scope['requirement_id'],
                'project_id' => (int) $scope['project_id'],
            ])
            ->unique(static fn (array $scope): string => $scope['requirement_id'].':'.$scope['project_id'])
            ->sortBy([
                ['requirement_id', 'asc'],
                ['project_id', 'asc'],
            ])
            ->each(fn (array $scope): mixed => $this->acquireScope(
                $scope['requirement_id'],
                $scope['project_id'],
            ));
    }

    public function acquire(int $projectVersionId): void
    {
        $this->assertTransaction();

        DB::select(
            <<<'SQL'
                SELECT pg_advisory_xact_lock(
                    hashtextextended('itpms:project-version:' || ?::text, 0)
                )
                SQL,
            [$projectVersionId],
        );
    }

    /**
     * @param  iterable<int>  $projectVersionIds
     */
    public function acquireVersions(iterable $projectVersionIds): void
    {
        collect($projectVersionIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->each(fn (int $id): mixed => $this->acquire($id));
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

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Version gate locks require an active database transaction.');
        }
    }
}
