<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ThemDeTaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'name'         => 'required|string|max:255',
            'teacher'      => 'required|string',
            'slots'        => 'required|string',
            'status'       => 'sometimes|string|in:pending,approved,rejected',
            'rejectReason' => 'required_if:status,rejected|nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'             => 'Tên đề tài không được để trống.',
            'name.string'               => 'Tên đề tài phải là chuỗi.',
            'name.max'                  => 'Tên đề tài không được vượt quá 255 ký tự.',
            'teacher.required'          => 'Tên giảng viên không được để trống.',
            'slots.required'            => 'Số lượng thành viên tối đa không được để trống.',
            'status.in'                 => 'Trạng thái không hợp lệ (hợp lệ: pending, approved, rejected).',
            'rejectReason.required_if'  => 'Lý do từ chối là bắt buộc khi chuyển trạng thái đề tài sang Từ chối!',
            'rejectReason.max'          => 'Lý do từ chối không được vượt quá 1000 ký tự.',
        ];
    }
}
