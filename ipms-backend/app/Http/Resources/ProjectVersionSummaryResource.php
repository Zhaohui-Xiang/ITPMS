<?php

namespace App\Http\Resources;

use App\Models\ProjectVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

/**
 * @mixin ProjectVersion
 */
final class ProjectVersionSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = (new ProjectVersionResource($this->resource))->resolve($request);

        return Arr::only($detail, [
            'id',
            'code',
            'name',
            'description',
            'status',
            'status_code',
            'status_label',
            'project',
            'owner',
            'planned_start_date',
            'planned_release_date',
            'released_at',
            'release_notes',
            'lock_version',
            'counts',
            'allowed_actions',
            'created_at',
            'updated_at',
        ]);
    }
}
