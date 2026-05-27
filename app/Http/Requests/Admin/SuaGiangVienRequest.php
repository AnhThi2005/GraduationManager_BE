<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuaGiangVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasRole('ADMIN');
    }

    public function rules(): array
    {
        $id = $this->route('id');   // giang_vien_id từ URL

        return [
            'ho_ten'        => 'required|string|max:255',
            'email'         => [
                'required',
                'email',
                'max:255',
                Rule::unique('giangvien', 'email')->ignore($id, 'giang_vien_id')
            ],
            'so_dien_thoai' => 'nullable|string|max:10',
            'chuyen_mon'    => 'required|string|max:255',
            'vai_tro'       => 'required|in:GIANG_VIEN,ADMIN',
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Email đã được sử dụng bởi giảng viên khác.',
        ];
    }
}