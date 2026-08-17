<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->hasPermission('document.create');
    }

    public function rules(): array
    {
        return [
            'folder_id' => ['nullable', 'integer', 'exists:folders,id'],
            'api_name' => ['required', 'string', 'max:200'],
            'request_path' => ['required', 'string', 'max:500'],
            'request_method' => ['required', 'string', 'in:GET,POST,PUT,PATCH,DELETE'],
            'auth_type' => ['nullable', 'string', 'max:50'],
            'request_params' => ['nullable', 'array'],
            'response_params' => ['nullable', 'array'],
            'rich_text_body' => ['nullable', 'string'],
            'requirement_id' => ['nullable', 'integer', 'exists:requirements,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'api_name.required' => '接口名称不能为空',
            'request_path.required' => '请求路径不能为空',
            'request_method.required' => '请求方法不能为空',
            'request_method.in' => '请求方法无效',
            'requirement_id.exists' => '关联需求不存在',
        ];
    }
}
