<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ProjectIndexContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_index_uses_page_size_contract(): void
    {
        $user = User::factory()->superAdmin()->create();
        Project::factory()->count(3)->create();

        $this->actingAs($user)
            ->getJson('/api/projects?page=1&page_size=2')
            ->assertOk()
            ->assertJsonStructure([
                'code',
                'message',
                'data' => ['items', 'page', 'page_size', 'total', 'total_pages'],
            ])
            ->assertJsonPath('data.page_size', 2);
    }
}
