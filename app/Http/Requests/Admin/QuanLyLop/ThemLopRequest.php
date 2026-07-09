<?php

namespace App\Http\Requests\Admin\QuanLyLop;

use Illuminate\Foundation\Http\FormRequest;

class ThemLopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Mã lớp không được để trống.',
            'code.string' => 'Mã lớp phải là chuỗi.',
            'code.max' => 'Mã lớp không được vượt quá 255 ký tự.',
            'name.required' => 'Tên lớp không được để trống.',
            'name.string' => 'Tên lớp phải là chuỗi.',
            'name.max' => 'Tên lớp không được vượt quá 255 ký tự.',
        ];
    }
}
