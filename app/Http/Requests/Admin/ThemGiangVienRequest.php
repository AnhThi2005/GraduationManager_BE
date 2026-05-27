<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ThemGiangVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasRole('ADMIN');
    }

    public function rules(): array
    {
        return [
            'ho_ten'        => 'required|string|max:255',
            'email'         => 'required|email|max:255|unique:giangvien,email',
            'so_dien_thoai' => 'nullable|string|max:10',
            'chuyen_mon'    => 'required|string|max:255',
            'vai_tro'       => 'required|in:GIANG_VIEN,ADMIN',
            'password'      => 'nullable|string|min:6',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'   => 'Email đã được sử dụng.',
            'vai_tro.in'     => 'Vai trò không hợp lệ.',
        ];
    }
}