<?php

namespace App\Services;

use App\Exceptions\DomainConflictException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class VersionGateLock
{
    public const MUTATION_LOCKED_SQLSTATE = 'IV001';

    public const SCOPE_BUSY_SQLSTATE = 'IV004';

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

    public function runAuthorizedRequirementProjectInsert(
        int $requirementId,
        int $projectId,
        callable $callback,
    ): mixed {
        $this->acquireScope($requirementId, $projectId);
        $this->setRequirementProjectInsertScope($requirementId, $projectId);

        try {
            $result = $callback();
        } catch (QueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->clearRequirementProjectInsertScope();

            throw $exception;
        }

        $this->clearRequirementProjectInsertScope();

        return $result;
    }

    private function setRequirementProjectInsertScope(
        int $requirementId,
        int $projectId,
    ): void {
        $this->assertTransaction();

        DB::select(
            <<<'SQL'
                SELECT set_config(
                    'itpms.requirement_project_insert_scope',
                    ?,
                    true
                )
                SQL,
            [json_encode([
                'requirement_id' => $requirementId,
                'project_id' => $projectId,
            ], JSON_THROW_ON_ERROR)],
        );
    }

    private function clearRequirementProjectInsertScope(): void
    {
        DB::select(
            <<<'SQL'
                SELECT set_config(
                    'itpms.requirement_project_insert_scope',
                    '',
                    true
                )
                SQL,
        );
    }

    /**
     * Authorize only the already locked pivot rows for UPDATE or DELETE.
     *
     * @param  iterable<int>  $requirementProjectIds
     */
    public function authorizeRequirementProjectMutations(
        iterable $requirementProjectIds,
        ?int $releaseVersionId = null,
        ?int $acceptanceVersionId = null,
    ): void {
        $this->assertTransaction();

        $ids = collect($requirementProjectIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
        $encodedIds = json_encode($ids, JSON_THROW_ON_ERROR);

        DB::select(
            <<<'SQL'
                SELECT set_config(
                    'itpms.requirement_project_write_ids',
                    ?,
                    true
                )
                SQL,
            [$encodedIds],
        );

        DB::select(
            <<<'SQL'
                SELECT set_config(
                    'itpms.release_project_version_id',
                    ?,
                    true
                )
                SQL,
            [$releaseVersionId === null ? '' : (string) $releaseVersionId],
        );
        DB::select(
            <<<'SQL'
                SELECT set_config(
                    'itpms.acceptance_project_version_id',
                    ?,
                    true
                )
                SQL,
            [$acceptanceVersionId === null ? '' : (string) $acceptanceVersionId],
        );
    }

    public function runAuthorizedRequirementProjectMutations(
        iterable $requirementProjectIds,
        callable $callback,
        ?int $releaseVersionId = null,
        ?int $acceptanceVersionId = null,
    ): mixed {
        $this->authorizeRequirementProjectMutations(
            $requirementProjectIds,
            $releaseVersionId,
            $acceptanceVersionId,
        );

        try {
            $result = $callback();
        } catch (QueryException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->authorizeRequirementProjectMutations([]);

            throw $exception;
        }

        $this->authorizeRequirementProjectMutations([]);

        return $result;
    }

    public static function mutationConflict(QueryException $exception): ?DomainConflictException
    {
        $sqlState = $exception->errorInfo[0] ?? $exception->getCode();

        if ($sqlState === self::SCOPE_BUSY_SQLSTATE) {
            return new DomainConflictException(
                'VERSION_SCOPE_BUSY',
                errors: ['requirement_project' => ['reload_required']],
                message: 'The requirement scope changed outside its controlled workflow. Reload and try again.',
            );
        }

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
            message: 'The project version no longer accepts requirement scope mapping, task, or defect mutations.',
        );
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Version gate locks require an active database transaction.');
        }
    }
}
