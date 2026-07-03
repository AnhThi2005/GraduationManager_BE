<?php

namespace App\Http\Requests\Admin\QuanLyDot;

use Illuminate\Foundation\Http\FormRequest;

class ThemDotRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255',
            'type'        => 'required|string|in:tttn,datn',
            'startDate'   => 'required|string',
            'endDate'     => 'required|string',
            'regDeadline' => 'required|string',
            'regOpenDate' => 'nullable|string',
            'reportDeadline' => 'nullable|string',
            'gradingStartDate' => 'nullable|string',
            'gradingEndDate' => 'nullable|string',
            'semester'    => 'nullable',
            'schoolYear'  => 'nullable|string',
            'classIds'    => 'nullable|array',
            'externalStudentIds' => 'nullable|array',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'        => 'Tên đợt tốt nghiệp không được để trống.',
            'name.string'          => 'Tên đợt tốt nghiệp phải là chuỗi.',
            'name.max'             => 'Tên đợt tốt nghiệp không được vượt quá 255 ký tự.',
            'type.required'        => 'Loại đợt tốt nghiệp là bắt buộc.',
            'type.in'              => 'Loại đợt tốt nghiệp phải là tttn hoặc datn.',
            'startDate.required'   => 'Ngày bắt đầu không được để trống.',
            'endDate.required'     => 'Ngày kết thúc không được để trống.',
            'regDeadline.required' => 'Hạn đăng ký không được để trống.',
        ];
    }
}
