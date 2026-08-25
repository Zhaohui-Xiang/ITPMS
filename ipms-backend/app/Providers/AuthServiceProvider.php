<?php

namespace App\Providers;

use App\Models\Defect;
use App\Models\Project;
use App\Models\ProjectVersion;
use App\Models\Requirement;
use App\Models\Task;
use App\Policies\DefectPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\ProjectVersionPolicy;
use App\Policies\RequirementPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Requirement::class => RequirementPolicy::class,
        Project::class => ProjectPolicy::class,
        ProjectVersion::class => ProjectVersionPolicy::class,
        Task::class => TaskPolicy::class,
        Defect::class => DefectPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
