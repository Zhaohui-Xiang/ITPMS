<?php

namespace App\Http\Requests;

use App\Models\Defect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ResolveDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $defect = Defect::query()->findOrFail((int) $this->route('id'));

        return Gate::forUser($this->user())->allows('resolve', $defect);
    }

    public function rules(): array
    {
        return [
            'fix_description' => ['required', 'string', 'max:5000'],
        ];
    }
}
