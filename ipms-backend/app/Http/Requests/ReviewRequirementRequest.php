<?php

namespace App\Http\Requests;

use App\Models\Requirement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ReviewRequirementRequest extends FormRequest
{
    public function authorize(): bool
    {
        $requirement = Requirement::query()->find($this->route('id'));

        return $requirement !== null
            && Gate::forUser($this->user())->allows('approve', $requirement);
    }

    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:approve,reject'],
            'comment' => ['required_if:action,reject', 'nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'action.required' => '审核操作不能为空',
            'action.in' => '审核操作必须是 approve（通过）或 reject（驳回）',
            'comment.required_if' => '驳回时必须填写审核意见',
        ];
    }
}
