<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KhoaTaiKhoanGVRequest extends FormRequest
{
    public function authorize(): bool
    {
        $authUser = auth()->user();
        
        if (!$authUser || !$authUser->tokenCan('ADMIN')) {
            return false;
        }

        if ($this->input('dang_hoat_dong') == 0 && $this->input('id') == $authUser->giang_vien_id) {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'id'             => 'required|integer|exists:giangvien,giang_vien_id',
            'dang_hoat_dong' => 'required|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'id.required'             => 'ID giảng viên không được để trống.',
            'id.exists'               => 'Giảng viên không tồn tại trong hệ thống.',
            'dang_hoat_dong.required' => 'Vui lòng cung cấp trạng thái hoạt động mới.',
            'dang_hoat_dong.boolean'  => 'Trạng thái hoạt động phải là đúng hoặc sai (1 hoặc 0).'
        ];
    }
}