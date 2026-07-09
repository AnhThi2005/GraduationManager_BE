<?php

namespace App\Http\Requests\Admin\QuanLyNguoiDung;

use Illuminate\Foundation\Http\FormRequest;

class LocNguoiDungUnifiedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'role' => 'nullable|string|in:student,teacher',
            'limit' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:255',
            'className' => 'nullable|string|max:255',
            'status' => 'nullable|string|in:active,inactive,deleted',
        ];
    }

    public function messages(): array
    {
        return [
            'role.in' => 'Vai trò không hợp lệ (phải là student hoặc teacher).',
            'limit.min' => 'Giới hạn số lượng tối thiểu là 1.',
            'limit.max' => 'Giới hạn số lượng tối đa là 100.',
            'status.in' => 'Trạng thái hoạt động không hợp lệ.',
        ];
    }
}
