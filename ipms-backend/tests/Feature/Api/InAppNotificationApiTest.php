<?php

namespace Tests\Feature\Api;

use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InAppNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_only_lists_their_notifications_newest_first(): void
    {
        $user = User::factory()->create();
        $old = InAppNotification::factory()->for($user, 'recipient')->create(['created_at' => now()->subDay()]);
        $new = InAppNotification::factory()->for($user, 'recipient')->create();
        InAppNotification::factory()->create(['title' => 'Other recipient private title']);

        $this->actingAs($user)->getJson('/api/notifications')
            ->assertOk()->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $new->id)
            ->assertJsonPath('data.items.1.id', $old->id)
            ->assertJsonPath('data.items.0.target_url', $new->target_url)
            ->assertJsonPath('data.items.0.read_at', null)
            ->assertJsonMissing(['title' => 'Other recipient private title']);
    }

    public function test_count_only_includes_current_users_unread_items(): void
    {
        $user = User::factory()->create();
        InAppNotification::factory()->for($user, 'recipient')->count(2)->create();
        InAppNotification::factory()->for($user, 'recipient')->create(['read_at' => now()]);
        InAppNotification::factory()->count(3)->create();
        $this->actingAs($user)->getJson('/api/notifications/unread-count')
            ->assertOk()->assertJsonPath('data.unread_count', 2);
    }

    public function test_single_read_is_persisted_and_idempotent(): void
    {
        $item = InAppNotification::factory()->create();
        $this->actingAs($item->recipient)->postJson("/api/notifications/{$item->id}/read")
            ->assertOk()->assertJsonPath('data.id', $item->id);
        $firstRead = $item->fresh()->read_at;
        $this->assertNotNull($firstRead);
        $this->travel(10)->minutes();
        $this->postJson("/api/notifications/{$item->id}/read")->assertOk();
        $this->assertTrue($item->fresh()->read_at->equalTo($firstRead));
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
    }

    public function test_foreign_and_missing_ids_return_not_found_without_changes(): void
    {
        $item = InAppNotification::factory()->create();
        $user = User::factory()->create();
        $this->actingAs($user)->postJson("/api/notifications/{$item->id}/read")->assertNotFound();
        $this->postJson('/api/notifications/999999/read')->assertNotFound();
        $this->assertNull($item->fresh()->read_at);
    }

    public function test_read_all_only_updates_current_users_unread_rows(): void
    {
        $user = User::factory()->create();
        InAppNotification::factory()->for($user, 'recipient')->count(3)->create();
        $oldRead = now()->subDay()->startOfSecond();
        $read = InAppNotification::factory()->for($user, 'recipient')->create(['read_at' => $oldRead]);
        $other = InAppNotification::factory()->create();
        $this->actingAs($user)->postJson('/api/notifications/read-all')
            ->assertOk()->assertJsonPath('data.updated_count', 3)->assertJsonPath('data.unread_count', 0);
        $this->assertNull($other->fresh()->read_at);
        $this->assertTrue($read->fresh()->read_at->equalTo($oldRead));
        $this->postJson('/api/notifications/read-all')->assertOk()->assertJsonPath('data.updated_count', 0);
    }

    public function test_pagination_is_stable_and_page_size_is_bounded(): void
    {
        $user = User::factory()->create();
        $items = InAppNotification::factory()->for($user, 'recipient')->count(3)->create(['created_at' => now()]);
        $this->actingAs($user)->getJson('/api/notifications?page=2&page_size=1')
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.id', $items[1]->id)
            ->assertJsonPath('data.total', 3)->assertJsonPath('data.page', 2)->assertJsonPath('data.total_pages', 3);
        $this->getJson('/api/notifications?page_size=999')->assertJsonPath('data.page_size', 100);
        $this->getJson('/api/notifications?page_size=0')->assertJsonPath('data.page_size', 1);
    }

    public function test_empty_inbox_has_zero_count_and_no_items(): void
    {
        $this->actingAs(User::factory()->create())->getJson('/api/notifications')
            ->assertOk()->assertJsonPath('data.total', 0)->assertJsonCount(0, 'data.items');
        $this->getJson('/api/notifications/unread-count')->assertJsonPath('data.unread_count', 0);
        $this->postJson('/api/notifications/read-all')->assertJsonPath('data.updated_count', 0);
    }

    public function test_all_endpoints_require_authentication(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
        $this->postJson('/api/notifications/1/read')->assertUnauthorized();
        $this->postJson('/api/notifications/read-all')->assertUnauthorized();
    }

    public function test_superadmin_cannot_query_another_recipients_inbox(): void
    {
        $other = InAppNotification::factory()->create();
        $this->actingAs(User::factory()->superAdmin()->create())
            ->getJson('/api/notifications?user_id='.$other->user_id)
            ->assertOk()->assertJsonCount(0, 'data.items');
        $this->postJson("/api/notifications/{$other->id}/read")->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsafeTargets')]
    public function test_notification_links_reject_external_or_ambiguous_targets(string $url): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new InAppNotification(['target_url' => $url]);
    }

    public static function unsafeTargets(): array
    {
        return [
            ['https://example.test/tasks'],
            ['//example.test/tasks'],
            ['/\\\\example.test/tasks'],
            ["/\n/example.test/tasks"],
        ];
    }

    public function test_deduplication_key_is_unique_in_the_database(): void
    {
        $item = InAppNotification::factory()->create();
        $this->expectException(QueryException::class);
        InAppNotification::factory()->create(['dedup_key' => $item->dedup_key]);
    }
}
