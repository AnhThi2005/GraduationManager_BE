<?php

namespace App\Http\Requests\Admin\QuanLyNguoiDung;

use Illuminate\Foundation\Http\FormRequest;

class KhoaTaiKhoanSVRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'id'             => 'required|integer|exists:sinhvien,sinh_vien_id',
            'dang_hoat_dong' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required'             => 'ID sinh viên không được để trống.',
            'id.exists'               => 'Sinh viên không tồn tại trong hệ thống.',
            'dang_hoat_dong.required' => 'Vui lòng cung cấp trạng thái hoạt động mới.',
            'dang_hoat_dong.boolean'  => 'Trạng thái hoạt động phải là đúng hoặc sai (1 hoặc 0).'
        ];
    }
}