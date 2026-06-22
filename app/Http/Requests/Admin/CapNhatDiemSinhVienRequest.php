<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CapNhatDiemSinhVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'status'       => 'sometimes|string|in:draft,reviewing,finalized',
            'finalScore'   => 'sometimes|numeric|min:0|max:10',
            'defenseScore' => 'sometimes|numeric|min:0|max:3',
            'demoScore'    => 'sometimes|numeric|min:0|max:5',
            'qaScore'      => 'sometimes|numeric|min:0|max:2',
            'reportScore'  => 'sometimes|numeric|min:0|max:10',
            'mode'         => 'required|string|in:internship,project',
            'dot_id'       => 'sometimes|integer',
        ];
    }

    public function messages(): array
    {
        return [
            'status.in'             => 'Trạng thái không hợp lệ (hợp lệ: draft, reviewing, finalized).',
            'finalScore.numeric'    => 'Điểm tổng kết phải là số.',
            'finalScore.min'        => 'Điểm tổng kết không được nhỏ hơn 0.',
            'finalScore.max'        => 'Điểm tổng kết không được lớn hơn 10.',
            'defenseScore.numeric'  => 'Điểm bảo vệ phải là số.',
            'defenseScore.min'      => 'Điểm bảo vệ không được nhỏ hơn 0.',
            'defenseScore.max'      => 'Điểm bảo vệ không được lớn hơn 3.',
            'demoScore.numeric'     => 'Điểm demo phải là số.',
            'demoScore.min'         => 'Điểm demo không được nhỏ hơn 0.',
            'demoScore.max'         => 'Điểm demo không được lớn hơn 5.',
            'qaScore.numeric'       => 'Điểm hỏi đáp phải là số.',
            'qaScore.min'           => 'Điểm hỏi đáp không được nhỏ hơn 0.',
            'qaScore.max'           => 'Điểm hỏi đáp không được lớn hơn 2.',
            'reportScore.numeric'   => 'Điểm báo cáo phải là số.',
            'reportScore.min'       => 'Điểm báo cáo không được nhỏ hơn 0.',
            'reportScore.max'       => 'Điểm báo cáo không được lớn hơn 10.',
            'mode.required'         => 'Chế độ (mode) là bắt buộc.',
            'mode.in'               => 'Chế độ phải là internship hoặc project.',
            'dot_id.integer'        => 'ID đợt tốt nghiệp phải là số nguyên.',
        ];
    }
}
