<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ThemDoanhNghiepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'name'  => 'required|string|max:255',
            'taxId' => 'required|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'  => 'Tên doanh nghiệp không được để trống.',
            'name.string'    => 'Tên doanh nghiệp phải là chuỗi.',
            'name.max'       => 'Tên doanh nghiệp không được vượt quá 255 ký tự.',
            'taxId.required' => 'Mã số thuế không được để trống.',
            'taxId.string'   => 'Mã số thuế phải là chuỗi.',
            'taxId.max'      => 'Mã số thuế không được vượt quá 255 ký tự.',
        ];
    }
}
