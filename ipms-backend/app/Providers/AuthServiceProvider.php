<?php

namespace App\Providers;

use App\Models\Defect;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\Task;
use App\Policies\DefectPolicy;
use App\Policies\ProjectPolicy;
use App\Policies\RequirementPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;

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
