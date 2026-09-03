<?php

namespace App\Http\Requests;

use App\Models\Defect;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class ReopenDefectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $defect = Defect::query()->findOrFail((int) $this->route('id'));

        return Gate::forUser($this->user())->allows('reopen', $defect);
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
