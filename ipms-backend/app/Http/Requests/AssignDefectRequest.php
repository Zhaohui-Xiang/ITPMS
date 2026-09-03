<?php

namespace App\Http\Requests;

use App\Models\Defect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class AssignDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $defect = Defect::query()->findOrFail((int) $this->route('id'));

        return Gate::forUser($this->user())->allows('assign', $defect);
    }

    public function rules(): array
    {
        return [
            'assignee_id' => ['required', 'integer:strict', 'exists:users,id'],
        ];
    }
}
