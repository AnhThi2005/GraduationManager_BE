<?php

namespace App\Http\Requests\Admin\QuanLySinhVienThucTap;

use Illuminate\Foundation\Http\FormRequest;

class ThemMoiXacNhanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'studentId' => 'required|string',
            'companyName' => 'required|string',
            'taxId' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'studentId.required' => 'Mã số sinh viên không được để trống.',
            'companyName.required' => 'Tên doanh nghiệp không được để trống.',
            'taxId.required' => 'Mã số thuế không được để trống.',
        ];
    }
}
