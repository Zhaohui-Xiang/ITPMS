<?php

namespace Database\Factories;

use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class InAppNotificationFactory extends Factory
{
    protected $model = InAppNotification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'event_code' => 'TASK_ASSIGNED',
            'dedup_key' => fake()->unique()->uuid(),
            'title' => 'Task assigned',
            'body' => 'A task has been assigned to you.',
            'target_type' => 'task',
            'target_id' => null,
            'target_url' => '/tasks',
            'payload' => [],
            'read_at' => null,
        ];
    }
}
