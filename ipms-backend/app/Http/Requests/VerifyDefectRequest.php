<?php

namespace App\Http\Requests;

use App\Models\Defect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class VerifyDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $defect = Defect::query()->findOrFail((int) $this->route('id'));

        return Gate::forUser($this->user())->allows('verify', $defect);
    }

    public function rules(): array
    {
        return [
            'result' => ['required', 'string', 'in:pass,fail'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
