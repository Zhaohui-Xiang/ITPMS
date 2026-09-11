<?php

namespace Tests\Feature\Api;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_document_mutations_write_valid_audit_records_and_nested_files_are_listed(): void
    {
        Storage::fake('public');
        $admin = User::factory()->superAdmin()->create();
        $project = Project::factory()->create(['manager_id' => $admin->id]);
        $root = $this->actingAs($admin)->postJson("/api/projects/{$project->id}/documents/folder", ['name' => 'Root'])
            ->assertCreated()->json('data.id');
        $child = $this->postJson("/api/projects/{$project->id}/documents/folder", ['name' => 'Child', 'parent_id' => $root])
            ->assertCreated()->json('data.id');
        $doc = $this->post("/api/projects/{$project->id}/documents/upload", [
            'folder_id' => $child, 'file' => UploadedFile::fake()->create('spec.pdf', 2, 'application/pdf'),
        ])->assertCreated()->json('data.id');
        $this->getJson("/api/projects/{$project->id}/documents")->assertOk()
            ->assertJsonPath('data.folders.0.children.0.documents.0.id', $doc);
        $this->get("/api/documents/{$doc}/download")->assertOk();
        $this->deleteJson("/api/documents/{$doc}")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['target_type' => 'document', 'target_id' => (string) $doc, 'module' => 5, 'action_type' => 3]);
    }

    public function test_cross_project_folder_upload_is_rejected_without_storing_file(): void
    {
        Storage::fake('public');
        $admin = User::factory()->superAdmin()->create();
        $a = Project::factory()->create();
        $b = Project::factory()->create();
        $folder = $this->actingAs($admin)->postJson("/api/projects/{$a->id}/documents/folder", ['name' => 'A'])
            ->assertCreated()->json('data.id');
        $this->postJson("/api/projects/{$b->id}/documents/upload", [
            'folder_id' => $folder, 'file' => UploadedFile::fake()->create('foreign.pdf', 2),
        ])->assertUnprocessable();
        $this->assertCount(0, Storage::disk('public')->allFiles());
    }
}
