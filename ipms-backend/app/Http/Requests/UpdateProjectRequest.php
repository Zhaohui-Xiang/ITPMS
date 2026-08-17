<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isSuperAdmin();
    }

    public function rules(): array
    {
        $projectId = $this->route('id');

        return [
            'name' => ['required', 'string', 'max:100', Rule::unique('projects', 'name')->ignore($projectId)],
            'system_type' => ['required', 'integer', 'in:1,2'],
            'description' => ['nullable', 'string'],
            'status' => ['nullable', 'integer', 'in:1,2,3'],
            'manager_id' => ['required', 'integer', 'exists:users,id'],
            'supplier_org_id' => ['nullable', 'integer', 'exists:organizations,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '项目名称不能为空',
            'name.max' => '项目名称不能超过100个字符',
            'name.unique' => '项目名称已存在',
            'system_type.required' => '系统类型不能为空',
            'system_type.in' => '系统类型无效',
            'manager_id.required' => '项目负责人不能为空',
            'manager_id.exists' => '项目负责人不存在',
            'supplier_org_id.exists' => '供应商组织不存在',
        ];
    }
}
