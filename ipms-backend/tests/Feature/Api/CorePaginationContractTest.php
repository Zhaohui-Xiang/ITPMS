<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CorePaginationContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_lists_use_the_pagination_contract(): void
    {
        $user = User::factory()->superAdmin()->create();

        $this->actingAs($user);

        foreach ([
            '/api/projects?page=1&page_size=2',
            '/api/requirements?page=1&page_size=2',
            '/api/tasks?page=1&page_size=2',
            '/api/defects?page=1&page_size=2',
            '/api/users?page=1&page_size=2',
            '/api/audit-logs?page=1&page_size=2',
            '/api/notification-logs?page=1&page_size=2',
        ] as $uri) {
            $this->getJson($uri)
                ->assertOk()
                ->assertJsonStructure([
                    'code',
                    'message',
                    'data' => ['items', 'page', 'page_size', 'total', 'total_pages'],
                ])
                ->assertJsonPath('data.page_size', 2);
        }
    }

    public function test_project_api_document_list_uses_the_pagination_contract(): void
    {
        $user = User::factory()->superAdmin()->create();
        $project = Project::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/projects/{$project->id}/api-docs?page=1&page_size=2")
            ->assertOk()
            ->assertJsonStructure([
                'code',
                'message',
                'data' => ['items', 'page', 'page_size', 'total', 'total_pages'],
            ])
            ->assertJsonPath('data.page_size', 2);
    }
}
