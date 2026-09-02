<?php

namespace App\Http\Resources;

use App\Models\ProjectVersionHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ProjectVersionHistory
 */
final class ProjectVersionHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $this->relationLoaded('actor') ? $this->actor : null;

        return [
            'id' => $this->id,
            'event_type' => $this->event_type,
            'from_status' => $this->from_status?->value,
            'from_status_code' => $this->from_status?->name,
            'to_status' => $this->to_status?->value,
            'to_status_code' => $this->to_status?->name,
            'reason' => $this->reason,
            'metadata' => $this->metadata,
            'actor' => $actor === null ? null : [
                'id' => $actor->id,
                'display_name' => $actor->display_name,
            ],
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
