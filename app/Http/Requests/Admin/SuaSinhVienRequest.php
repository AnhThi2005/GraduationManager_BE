<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuaSinhVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasRole('ADMIN');
    }

    public function rules(): array
    {
        $id = $this->route('id');   // sinh_vien_id từ URL

        return [
            'ho_ten'        => 'required|string|max:255',
            'email'         => [
                'required',
                'email',
                'max:255',
                Rule::unique('sinhvien', 'email')->ignore($id, 'sinh_vien_id')
            ],
            'so_dien_thoai' => 'nullable|string|max:10',
            'lop'           => 'required|string|max:50',
            'khoa_hoc'      => 'required|string|max:10',
            'chuyen_nganh'  => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Email đã được sử dụng bởi sinh viên khác.',
        ];
    }
}