<?php

namespace Tests\Feature\Api;

use App\Enums\DefectSeverity;
use App\Enums\DefectStatus;
use App\Enums\ProjectDeliveryStatus;
use App\Enums\RequirementStatus;
use App\Models\Defect;
use App\Models\DefectAttachment;
use App\Models\Organization;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class DefectAttachmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Storage::fake('public');
        $this->seed([
            RoleSeeder::class,
            PermissionSeeder::class,
            RolePermissionSeeder::class,
        ]);
    }

    public function test_reporter_uploads_downloads_and_deletes_an_attachment(): void
    {
        $fixture = $this->fixture();

        $created = $this->actingAs($fixture['reporter'])
            ->post("/api/defects/{$fixture['defect']->id}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('证据.png', 'fake-image-bytes'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.filename', '证据.png');

        $attachmentId = $created->json('data.id');
        Storage::disk('public')->assertExists(
            DefectAttachment::query()->findOrFail($attachmentId)->file,
        );

        // 缺陷详情带出附件列表
        $this->actingAs($fixture['reporter'])
            ->getJson("/api/defects/{$fixture['defect']->id}")
            ->assertOk()
            ->assertJsonPath('data.attachments.0.id', $attachmentId)
            ->assertJsonPath('data.attachments.0.filename', '证据.png');

        $this->actingAs($fixture['reporter'])
            ->get("/api/defect-attachments/{$attachmentId}/download")
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->actingAs($fixture['reporter'])
            ->deleteJson("/api/defect-attachments/{$attachmentId}")
            ->assertOk();
        $this->assertSoftDeleted('defect_attachments', ['id' => $attachmentId]);
    }

    public function test_unrelated_user_cannot_upload_download_or_delete(): void
    {
        $fixture = $this->fixture();
        $unrelated = User::factory()->withRole('supplier_tester')->create();
        $unrelated->organizations()->attach($fixture['outsideSupplier']->id, [
            'role_in_org' => 'member', 'is_primary' => true, 'assigned_at' => now(),
        ]);

        $this->actingAs($unrelated)
            ->post("/api/defects/{$fixture['defect']->id}/attachments", [
                'file' => UploadedFile::fake()->createWithContent('x.png', 'bytes'),
            ])
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $attachment = DefectAttachment::query()->create([
            'defect_id' => $fixture['defect']->id,
            'file' => 'defects/x.png',
            'filename' => 'x.png',
            'file_size' => 5,
            'file_type' => 'image/png',
            'uploaded_by_id' => $fixture['reporter']->id,
        ]);
        Storage::disk('public')->put('defects/x.png', 'bytes');

        $this->actingAs($unrelated)
            ->get("/api/defect-attachments/{$attachment->id}/download")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($unrelated)
            ->deleteJson("/api/defect-attachments/{$attachment->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');
    }

    public function test_non_uploader_member_cannot_delete_others_attachment(): void
    {
        $fixture = $this->fixture();
        $member = User::factory()->withRole('it_member')->create();
        DB::table('project_members')->insert([
            'project_id' => $fixture['project']->id,
            'user_id' => $member->id,
            'role_in_project' => 'member',
            'assigned_by_id' => $fixture['itPm']->id,
            'assigned_at' => now(),
            'created_at' => now(),
        ]);
        $attachment = DefectAttachment::query()->create([
            'defect_id' => $fixture['defect']->id,
            'file' => 'defects/y.png',
            'filename' => 'y.png',
            'file_size' => 5,
            'file_type' => 'image/png',
            'uploaded_by_id' => $fixture['reporter']->id,
        ]);

        $this->actingAs($member)
            ->deleteJson("/api/defect-attachments/{$attachment->id}")
            ->assertForbidden()
            ->assertJsonPath('error_code', 'FORBIDDEN');

        $this->actingAs($fixture['superadmin'])
            ->deleteJson("/api/defect-attachments/{$attachment->id}")
            ->assertOk();
    }

    public function test_upload_validates_file_presence(): void
    {
        $fixture = $this->fixture();

        $this->actingAs($fixture['reporter'])
            ->postJson("/api/defects/{$fixture['defect']->id}/attachments", [])
            ->assertUnprocessable()
            ->assertJsonPath('error_code', 'VALIDATION_FAILED');
    }

    private function fixture(): array
    {
        $itPm = User::factory()->withRole('it_pm')->create();
        $reporter = User::factory()->withRole('supplier_tester')->create();
        $superadmin = User::factory()->superAdmin()->create();

        $supplier = Organization::query()->create([
            'name' => 'Attachment supplier', 'org_type' => 2, 'is_active' => true,
        ]);
        $outsideSupplier = Organization::query()->create([
            'name' => 'Outside supplier', 'org_type' => 2, 'is_active' => true,
        ]);
        $reporter->organizations()->attach($supplier, [
            'role_in_org' => 'member', 'is_primary' => true, 'assigned_at' => now(),
        ]);

        $project = Project::factory()->create([
            'manager_id' => $itPm->id,
            'supplier_org_id' => $supplier->id,
            'created_by_id' => $itPm->id,
            'status' => 1,
        ]);
        $requirement = Requirement::factory()->create([
            'submitter_id' => $itPm->id,
            'created_by_id' => $itPm->id,
            'status' => RequirementStatus::ASSIGNED->value,
        ]);
        RequirementProject::factory()
            ->for($requirement)
            ->for($project)
            ->create(['delivery_status' => ProjectDeliveryStatus::ASSIGNED]);

        $defect = Defect::factory()->create([
            'requirement_id' => $requirement->id,
            'project_id' => $project->id,
            'reporter_id' => $reporter->id,
            'created_by_id' => $reporter->id,
            'severity' => DefectSeverity::SERIOUS->value,
            'status' => DefectStatus::PENDING_CONFIRM->value,
        ]);

        return compact('itPm', 'reporter', 'superadmin', 'supplier', 'outsideSupplier', 'project', 'requirement', 'defect');
    }
}
