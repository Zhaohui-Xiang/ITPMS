<?php

namespace App\Http\Controllers\Api;

use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\ProjectVersionStatus;
use App\Enums\RequirementStatus;
use App\Enums\TaskStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\Task;
use App\Models\User;
use App\Scopes\DefectScope;
use App\Scopes\ProjectScope;
use App\Scopes\RequirementScope;
use App\Scopes\TaskScope;
use App\Services\ReleaseGateService;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends Controller
{
    private const QUEUE_LIMIT = 10;

    private const DUE_WINDOW_DAYS = 7;

    public function __construct(
        private readonly ReleaseGateService $releaseGateService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $this->primaryRole($user);
        $visibleProjects = ProjectScope::apply(Project::query(), $user);
        $visibleProjectIds = (clone $visibleProjects)
            ->pluck('projects.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $requirements = RequirementScope::apply(
            Requirement::query()->with('projects:id,name'),
            $user,
        );
        $tasks = TaskScope::apply(
            Task::query()->with('project:id,name'),
            $user,
        );
        $defects = DefectScope::apply(
            Defect::query()->with('project:id,name'),
            $user,
        );

        $pendingReviews = $this->pendingReviews($requirements, $user);
        $dueTasks = $this->dueTasks($tasks, $user, $role);
        $pendingDefects = $this->pendingDefects($defects, $user, $role);
        $unplannedRequirements = RequirementProject::query()
            ->whereNull('project_version_id')
            ->whereIn(
                'requirement_id',
                (clone $requirements)->select('requirements.id'),
            )
            ->whereIn(
                'project_id',
                (clone $visibleProjects)->select('projects.id'),
            );

        // requester 视角：我的需求进展 + 待我验收（已上线待验收的交付）；仅 requester 才查询
        $isRequester = $role === 'requester';
        $myRequirements = $isRequester
            ? (clone $requirements)->where('submitter_id', $user->id)
            : null;
        $pendingAcceptance = $isRequester
            ? (clone $requirements)
                ->where('submitter_id', $user->id)
                ->whereHas('projects', fn (Builder $delivery): Builder => $delivery
                    ->where('requirement_project.delivery_status', ProjectDeliveryStatus::DEPLOYED->value))
            : null;

        $releaseEvaluation = $this->releaseEvaluation(
            $this->visibleVersionQuery($user)->get(),
        );
        $counts = [
            'pending_reviews' => (clone $pendingReviews)->count(),
            'due_tasks' => (clone $dueTasks)->count(),
            'pending_defects' => (clone $pendingDefects)->count(),
            'unplanned_requirements' => (clone $unplannedRequirements)->count(),
            'blocked_releases' => $releaseEvaluation['blocked_count'],
            'my_requirements' => $myRequirements === null ? 0 : (clone $myRequirements)->count(),
            'pending_acceptance' => $pendingAcceptance === null ? 0 : (clone $pendingAcceptance)->count(),
        ];

        return ApiResponse::success([
            'metrics' => $this->metrics($role, $counts, $user),
            'priority_queue' => $this->priorityQueue(
                $role,
                $pendingReviews,
                $dueTasks,
                $pendingDefects,
                $visibleProjectIds,
                $pendingAcceptance,
            ),
            'release_risks' => $releaseEvaluation['items'],
            'show_release_risks' => in_array($role, ['super_admin', 'it_pm', 'it_member'], true),
        ]);
    }

    private function dueTasks(Builder $tasks, User $user, string $role): Builder
    {
        $query = (clone $tasks)
            ->whereIn('status', [
                TaskStatus::TODO->value,
                TaskStatus::IN_PROGRESS->value,
                TaskStatus::SUSPENDED->value,
            ])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', today()->addDays(self::DUE_WINDOW_DAYS));

        // "我可执行"口径：执行类角色只看分配给自己的任务
        if (in_array($role, ['it_member', 'supplier_dev', 'supplier_tester'], true)) {
            $query->where('assignee_id', $user->id);
        }

        return $query;
    }

    /**
     * 待办口径=当前用户可执行：
     * - 审核角色（有 requirement.approve）：当前可审核的需求，排除已被驳回待提交人重送的；
     * - 提交人（requester）：被驳回待我修改重送的需求（其可执行动作是 resubmit/edit）；
     * - 其他角色：无可执行的审核事项。
     */
    private function pendingReviews(Builder $requirements, User $user): Builder
    {
        $query = (clone $requirements)
            ->where('status', RequirementStatus::PENDING_REVIEW->value);

        if ($user->hasPermission('requirement.approve')) {
            return $query->where(function (Builder $reviewable): void {
                $reviewable
                    ->whereNull('reviewer_id')
                    ->orWhereNull('reviewed_at')
                    ->orWhereNull('review_comment')
                    ->orWhere('review_comment', '');
            });
        }

        if ($user->user_type === UserType::SYSTEM_USER->value) {
            return $query
                ->whereNotNull('reviewer_id')
                ->whereNotNull('reviewed_at')
                ->whereNotNull('review_comment')
                ->where('review_comment', '!=', '');
        }

        return $query->whereRaw('1 = 0');
    }

    private function pendingDefects(
        Builder $defects,
        User $user,
        string $role,
    ): Builder {
        $query = (clone $defects)
            ->where('status', '!=', DefectStatus::CLOSED->value);

        if ($role === 'supplier_dev') {
            $query->where('assignee_id', $user->id);
        }
        if ($role === 'it_member') {
            // 我的缺陷：分配给我或我提交的
            $query->where(function (Builder $mine) use ($user): void {
                $mine->where('assignee_id', $user->id)
                    ->orWhere('reporter_id', $user->id);
            });
        }
        if ($role === 'supplier_tester') {
            $query
                ->where('status', DefectStatus::PENDING_RETEST->value)
                // 口径与 DefectPolicy::verify 一致：提交人或项目成员中的测试角色可复测
                ->where(function (Builder $retestScope) use ($user): void {
                    $retestScope
                        ->where('reporter_id', $user->id)
                        ->orWhereHas(
                            'project.members',
                            fn (Builder $memberQuery): Builder => $memberQuery
                                ->where('user_id', $user->id),
                        );
                });
        }

        return $query;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, value: int, target_url: string}>
     */
    private function metrics(string $role, array $counts, User $user): array
    {
        $keys = match ($role) {
            'super_admin', 'it_pm' => [
                'pending_reviews',
                'due_tasks',
                'pending_defects',
                'unplanned_requirements',
                'blocked_releases',
            ],
            // 执行视角：只看自己的任务与缺陷（供应商无版本菜单，不出受阻发布卡）
            'it_member' => [
                'due_tasks',
                'pending_defects',
                'unplanned_requirements',
                'blocked_releases',
            ],
            'supplier_pm' => [
                'due_tasks',
                'pending_defects',
                'unplanned_requirements',
            ],
            'supplier_dev', 'supplier_tester' => [
                'due_tasks',
                'pending_defects',
            ],
            // 提出人视角：我的需求进展 / 待我验收 / 待我重新提交 / 未关闭缺陷
            'requester' => [
                'my_requirements',
                'pending_acceptance',
                'pending_reviews',
                'pending_defects',
            ],
            default => [],
        };

        $labels = [
            'my_requirements' => '我的需求',
            'pending_acceptance' => '待我验收',
            'pending_reviews' => $role === 'requester' ? '待我重新提交' : '待审核需求',
            'due_tasks' => in_array($role, ['it_member', 'supplier_dev', 'supplier_tester'], true)
                ? '我的临期任务'
                : '临期任务',
            'pending_defects' => match ($role) {
                'it_member', 'supplier_dev' => '我的待处理缺陷',
                'supplier_tester' => '待复测缺陷',
                'requester' => '未关闭缺陷',
                default => '待处理缺陷',
            },
            'unplanned_requirements' => $role === 'requester' ? '待规划需求' : '未规划需求',
            'blocked_releases' => $role === 'requester' ? '关联发布风险' : '受阻发布',
        ];
        // 统计卡跳转带过滤参数，落点即是 filtered 列表
        $targets = [
            'my_requirements' => '/requirements',
            'pending_acceptance' => '/requirements?pending_acceptance=1',
            'pending_reviews' => '/requirements?status=1',
            'due_tasks' => in_array($role, ['it_member', 'supplier_dev', 'supplier_tester'], true)
                ? '/tasks?assignee_id='.$user->id
                : '/tasks',
            'pending_defects' => $role === 'supplier_tester' ? '/defects?status=4' : '/defects',
            'unplanned_requirements' => '/requirements',
            'blocked_releases' => '/projects',
        ];

        return collect($keys)->map(static fn (string $key): array => [
            'key' => $key,
            'label' => $labels[$key],
            'value' => (int) $counts[$key],
            'target_url' => $targets[$key],
        ])->all();
    }

    /**
     * @param  list<int>  $visibleProjectIds
     * @return list<array<string, mixed>>
     */
    private function priorityQueue(
        string $role,
        Builder $pendingReviews,
        Builder $dueTasks,
        Builder $pendingDefects,
        array $visibleProjectIds,
        ?Builder $pendingAcceptance = null,
    ): array {
        $items = collect();

        // 提出人视角：待我验收排在最前
        if ($role === 'requester' && $pendingAcceptance !== null) {
            (clone $pendingAcceptance)
                ->orderBy('priority')
                ->orderByRaw('CASE WHEN expected_completion_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expected_completion_date')
                ->limit(self::QUEUE_LIMIT)
                ->get()
                ->each(function (Requirement $requirement) use ($items): void {
                    $items->push($this->queueItem(
                        'requirement',
                        $requirement->id,
                        $requirement->title,
                        $requirement->projects->first()?->name,
                        $requirement->expected_completion_date?->toDateString(),
                        $this->riskRank((int) $requirement->priority),
                        "/requirements/{$requirement->id}",
                        '待验收',
                    ));
                });
        }

        if (in_array($role, ['super_admin', 'it_pm', 'requester'], true)) {
            (clone $pendingReviews)
                ->orderBy('priority')
                ->orderByRaw('CASE WHEN expected_completion_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expected_completion_date')
                ->limit(self::QUEUE_LIMIT)
                ->get()
                ->each(function (Requirement $requirement) use ($items, $visibleProjectIds, $role): void {
                    $project = $requirement->projects->first(
                        static fn (Project $project): bool => in_array(
                            (int) $project->id,
                            $visibleProjectIds,
                            true,
                        ),
                    );
                    $items->push($this->queueItem(
                        'requirement',
                        $requirement->id,
                        $requirement->title,
                        $project?->name,
                        $requirement->expected_completion_date?->toDateString(),
                        $this->riskRank((int) $requirement->priority),
                        "/requirements/{$requirement->id}",
                        $role === 'requester' ? '待重新提交' : '待审核',
                    ));
                });
        }

        if ($role !== 'requester') {
            (clone $dueTasks)
                ->orderBy('priority')
                ->orderBy('due_date')
                ->limit(self::QUEUE_LIMIT)
                ->get()
                ->each(function (Task $task) use ($items): void {
                    $overdue = $task->due_date !== null && $task->due_date->isPast();
                    $items->push($this->queueItem(
                        'task',
                        $task->id,
                        $task->title,
                        $task->project?->name,
                        $task->due_date?->toDateString(),
                        $this->riskRank((int) $task->priority),
                        "/tasks/{$task->id}",
                        $overdue ? '已逾期' : '临期',
                    ));
                });
        }

        (clone $pendingDefects)
            ->orderBy('severity')
            ->orderBy('discovered_at')
            ->limit(self::QUEUE_LIMIT)
            ->get()
            ->each(function (Defect $defect) use ($items): void {
                $items->push($this->queueItem(
                    'defect',
                    $defect->id,
                    $defect->title,
                    $defect->project?->name,
                    null,
                    $this->riskRank((int) $defect->severity),
                    "/defects/{$defect->id}",
                    DefectStatus::tryFrom((int) $defect->status)?->label() ?? '待处理',
                ));
            });

        return $this->sortAndLimitQueue($items);
    }

    /**
     * @param  Collection<int, ProjectVersion>  $versions
     * @return array{items: list<array<string, mixed>>, blocked_count: int}
     */
    private function releaseEvaluation(Collection $versions): array
    {
        $items = collect();
        $blockedCount = 0;
        $gateVersions = $versions->filter(
            fn (ProjectVersion $version): bool => in_array($version->status, [
                ProjectVersionStatus::IN_TESTING,
                ProjectVersionStatus::READY_TO_RELEASE,
            ], true),
        );
        $gateResults = $this->releaseGateService->checkMany(
            $gateVersions,
            static fn (ProjectVersion $version): ProjectVersionStatus => (
                $version->status === ProjectVersionStatus::IN_TESTING
                    ? ProjectVersionStatus::READY_TO_RELEASE
                    : ProjectVersionStatus::RELEASED
            ),
        );

        foreach ($versions as $version) {
            $rank = PHP_INT_MAX;
            $gateResult = $gateResults->get($version->id);
            $isBlocked = $gateResult !== null && ! $gateResult->passed;

            if ($isBlocked) {
                $blockedCount++;
                $rank = 1;
            }

            if ($version->releaseSnapshot?->is_override === true) {
                $rank = min($rank, 2);
            }

            $overdue = false;
            if (
                $version->status->value <= ProjectVersionStatus::READY_TO_RELEASE->value
                && $version->planned_release_date !== null
                && $version->planned_release_date->lte(today()->addDays(self::DUE_WINDOW_DAYS))
            ) {
                $overdue = $version->planned_release_date->lt(today());
                $dateRank = $overdue
                    ? 1
                    : ($version->planned_release_date->lte(today()->addDays(3)) ? 2 : 3);
                $rank = min($rank, $dateRank);
            }

            if ($rank === PHP_INT_MAX) {
                continue;
            }

            $reason = match (true) {
                $isBlocked => '门禁阻断',
                $version->releaseSnapshot?->is_override === true => '强制发布',
                $overdue => '已逾期',
                default => '临近发布',
            };

            $items->push($this->queueItem(
                'project_version',
                $version->id,
                trim($version->code.' '.$version->name),
                $version->project?->name,
                $version->planned_release_date?->toDateString()
                    ?? $version->released_at?->toISOString(),
                $rank,
                "/projects/{$version->project_id}/versions",
                $reason,
            ));
        }

        return [
            'items' => $this->sortAndLimitQueue($items),
            'blocked_count' => $blockedCount,
        ];
    }

    private function visibleVersionQuery(User $user): Builder
    {
        $visibleProjects = ProjectScope::apply(Project::query(), $user);
        $query = ProjectVersion::query()
            ->with([
                'project:id,name',
                'releaseSnapshot:id,project_version_id,is_override,released_at',
            ])
            ->whereIn('project_id', $visibleProjects->select('projects.id'));

        if (! $user->isSuperAdmin() && ! $user->hasPermission('project_version.view')) {
            $query->whereRaw('1 = 0');
        } elseif ($user->user_type === UserType::SYSTEM_USER->value) {
            $query->whereHas(
                'requirementLinks.requirement',
                fn (Builder $requirementQuery): Builder => $requirementQuery
                    ->where('submitter_id', $user->id),
            );
        }

        return $query
            ->where(function (Builder $candidateQuery): void {
                $candidateQuery
                    ->whereIn('status', [
                        ProjectVersionStatus::IN_TESTING->value,
                        ProjectVersionStatus::READY_TO_RELEASE->value,
                    ])
                    ->orWhereHas(
                        'releaseSnapshot',
                        fn (Builder $snapshotQuery): Builder => $snapshotQuery
                            ->where('is_override', true),
                    )
                    ->orWhere(function (Builder $dateQuery): void {
                        $dateQuery
                            ->where('status', '<=', ProjectVersionStatus::READY_TO_RELEASE->value)
                            ->whereNotNull('planned_release_date')
                            ->whereDate(
                                'planned_release_date',
                                '<=',
                                today()->addDays(self::DUE_WINDOW_DAYS),
                            );
                    });
            })
            ->orderBy('planned_release_date')
            ->orderBy('id');
    }

    /**
     * @return array<string, mixed>
     */
    private function queueItem(
        string $type,
        int $id,
        string $title,
        ?string $project,
        ?string $dueAt,
        int $rank,
        string $targetUrl,
        ?string $reason = null,
    ): array {
        return [
            'type' => $type,
            'id' => $id,
            'title' => $title,
            'project' => $project,
            'due_at' => $dueAt,
            'severity' => match ($rank) {
                1 => 'critical',
                2 => 'high',
                3 => 'medium',
                default => 'low',
            },
            'reason' => $reason,
            'target_url' => $targetUrl,
            '_risk_rank' => $rank,
            '_due_sort' => $dueAt ?? '9999-12-31T23:59:59Z',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function sortAndLimitQueue(Collection $items): array
    {
        return $items
            ->sortBy([
                ['_risk_rank', 'asc'],
                ['_due_sort', 'asc'],
                ['type', 'asc'],
                ['id', 'asc'],
            ])
            ->take(self::QUEUE_LIMIT)
            ->map(static function (array $item): array {
                unset($item['_risk_rank'], $item['_due_sort']);

                return $item;
            })
            ->values()
            ->all();
    }

    private function riskRank(int $value): int
    {
        return min(max($value, 1), 4);
    }

    private function primaryRole(User $user): string
    {
        $roles = $user->roles()->pluck('code');

        foreach ([
            'super_admin',
            'it_pm',
            'it_member',
            'supplier_pm',
            'supplier_tester',
            'supplier_dev',
            'requester',
        ] as $role) {
            if ($roles->contains($role)) {
                return $role;
            }
        }

        return 'guest';
    }

    /**
     * 首页统计
     * GET /api/dashboard/stats
     *
     * 返回：我的需求数、待处理任务数、未关闭缺陷数
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        // 我的需求数（可见范围内的需求总数）
        $reqQuery = Requirement::query();
        $reqQuery = RequirementScope::apply($reqQuery, $user);
        $requirementCount = $reqQuery->count();

        // 待处理任务数（状态为待开始或进行中，且我是负责人）
        $taskQuery = Task::query();
        $taskQuery = TaskScope::apply($taskQuery, $user);
        $pendingTaskCount = (clone $taskQuery)->whereIn('status', [
            TaskStatus::TODO->value,
            TaskStatus::IN_PROGRESS->value,
        ])->where('assignee_id', $user->id)->count();

        // 未关闭缺陷数（状态不是已关闭的）
        $defectQuery = Defect::query();
        $defectQuery = DefectScope::apply($defectQuery, $user);
        $openDefectCount = (clone $defectQuery)->where('status', '!=', DefectStatus::CLOSED->value)->count();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => [
                'requirement_count' => $requirementCount,
                'pending_task_count' => $pendingTaskCount,
                'open_defect_count' => $openDefectCount,
            ],
        ]);
    }

    /**
     * 近期需求
     * GET /api/dashboard/recent-requirements
     */
    public function recentRequirements(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Requirement::with([
            'submitter:id,display_name',
            'reviewer:id,display_name',
        ]);
        $query = RequirementScope::apply($query, $user);

        $requirements = $query->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $requirements,
        ]);
    }

    /**
     * 近期任务
     * GET /api/dashboard/recent-tasks
     */
    public function recentTasks(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Task::with([
            'assignee:id,display_name',
            'requirement:id,title',
            'project:id,name',
        ]);
        $query = TaskScope::apply($query, $user);

        $tasks = $query->orderBy('updated_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'code' => 200,
            'message' => 'success',
            'data' => $tasks,
        ]);
    }
}
