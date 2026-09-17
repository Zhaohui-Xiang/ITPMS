<?php

namespace Tests\Feature\Api;

use App\Models\ApiDocument;
use App\Models\Folder;
use App\Models\Project;
use App\Models\Requirement;
use App\Models\RequirementProject;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentPermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('public');
        $this->seed([RoleSeeder::class, PermissionSeeder::class, RolePermissionSeeder::class]);
    }

    public function test_it_pm_and_admin_create_api_docs_using_real_permissions(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->create(['manager_id' => $manager->id]);
        foreach ([$manager, User::factory()->superAdmin()->create()] as $user) {
            $this->actingAs($user)->postJson("/api/projects/{$project->id}/api-docs", [
                'api_name' => 'QA API', 'request_path' => '/qa', 'request_method' => 'GET',
            ])->assertCreated();
            $id = ApiDocument::query()->latest('id')->value('id');
            $this->putJson("/api/api-docs/{$id}", ['request_params' => null, 'response_params' => null])
                ->assertOk()->assertJsonPath('data.request_params', [])->assertJsonPath('data.response_params', []);
        }
    }

    public function test_requester_project_visibility_does_not_grant_document_privileges(): void
    {
        $requester = User::factory()->withRole('requester')->create();
        $project = Project::factory()->create();
        $requirement = Requirement::factory()->create(['submitter_id' => $requester->id]);
        RequirementProject::factory()->for($requirement)->for($project)->create();
        $doc = ApiDocument::create(['project_id' => $project->id, 'api_name' => 'Private',
            'request_path' => '/private', 'request_method' => 'GET', 'version' => 1]);
        $this->assertTrue($requester->can('view', $project));
        $this->actingAs($requester)->getJson("/api/projects/{$project->id}/documents")->assertForbidden();
        $this->postJson("/api/projects/{$project->id}/documents/folder", ['name' => 'Unauthorized'])->assertForbidden();
        $this->postJson("/api/projects/{$project->id}/documents/upload", [
            'file' => UploadedFile::fake()->create('denied.txt', 1),
        ])->assertForbidden();
        $this->getJson("/api/projects/{$project->id}/api-docs")->assertForbidden();
        $this->getJson("/api/api-docs/{$doc->id}")->assertForbidden();
        $this->putJson("/api/api-docs/{$doc->id}", ['api_name' => 'Unauthorized'])->assertForbidden();
        $this->getJson("/api/api-docs/{$doc->id}/versions")->assertForbidden();
        $this->postJson("/api/api-docs/{$doc->id}/export")->assertForbidden();
        $this->assertSame('Private', $doc->fresh()->api_name);
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }

    public function test_project_manager_can_upload_but_not_delete_documents(): void
    {
        $manager = User::factory()->withRole('it_pm')->create();
        $project = Project::factory()->create(['manager_id' => $manager->id]);
        $id = $this->actingAs($manager)->postJson("/api/projects/{$project->id}/documents/upload", [
            'file' => UploadedFile::fake()->create('readme.txt', 1),
        ])->assertCreated()->json('data.id');
        $this->get("/api/documents/{$id}/download")->assertOk();
        $this->deleteJson("/api/documents/{$id}")->assertForbidden();
        $this->actingAs(User::factory()->superAdmin()->create())->deleteJson("/api/documents/{$id}")->assertOk();
    }

    public function test_api_doc_relations_cannot_reference_another_project(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $project = Project::factory()->create();
        $foreign = Project::factory()->create();
        $folder = Folder::create(['project_id' => $foreign->id, 'name' => 'Private folder', 'level' => 0, 'created_by_id' => $admin->id]);
        $requirement = Requirement::factory()->create();
        RequirementProject::factory()->for($requirement)->for($foreign)->create();
        $payload = ['api_name' => 'Foreign link', 'request_path' => '/qa', 'request_method' => 'GET'];
        $this->actingAs($admin)->postJson("/api/projects/{$project->id}/api-docs", [
            ...$payload, 'folder_id' => $folder->id,
        ])->assertUnprocessable();
        $this->postJson("/api/projects/{$project->id}/api-docs", [
            ...$payload, 'requirement_id' => $requirement->id,
        ])->assertUnprocessable();
        $id = $this->postJson("/api/projects/{$project->id}/api-docs", $payload)->assertCreated()->json('data.id');
        $this->putJson("/api/api-docs/{$id}", ['requirement_id' => $requirement->id])->assertUnprocessable();
    }
}
