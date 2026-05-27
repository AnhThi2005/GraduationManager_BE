<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ThemSinhVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasRole('ADMIN');
    }

    public function rules(): array
    {
        return [
            'ma_so_sinh_vien' => 'required|string|max:10|unique:sinhvien,ma_so_sinh_vien',
            'ho_ten'          => 'required|string|max:255',
            'email'           => 'required|email|max:255|unique:sinhvien,email',
            'so_dien_thoai'   => 'nullable|string|max:10',
            'lop'             => 'required|string|max:50',
            'khoa_hoc'        => 'required|string|max:10',
            'chuyen_nganh'    => 'nullable|string|max:100',
            'password'        => 'nullable|string|min:6',
        ];
    }

    public function messages(): array
    {
        return [
            'ma_so_sinh_vien.unique' => 'Mã số sinh viên đã tồn tại.',
            'email.unique'           => 'Email đã được sử dụng.',
        ];
    }
}