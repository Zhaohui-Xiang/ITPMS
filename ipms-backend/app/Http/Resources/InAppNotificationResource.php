<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InAppNotificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_code' => $this->event_code,
            'title' => $this->title,
            'body' => $this->body,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'target_url' => $this->target_url,
            'payload' => $this->payload,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
