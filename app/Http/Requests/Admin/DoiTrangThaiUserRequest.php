<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class DoiTrangThaiUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $authUser = auth()->user();
        
        if (!$authUser || !$authUser->hasRole('ADMIN')) {
            return false;
        }

        // Không cho phép ADMIN tự khóa chính mình
        if ($this->id == $authUser->id && $this->trang_thai === 'KHOA') {
            return false;
        }

        return true;
    }

    public function rules(): array
    {
        return [
            'loai_doi_tuong' => 'required|in:SINH_VIEN,GIANG_VIEN',
            'id'             => 'required|integer',
            'trang_thai'     => 'required|in:HOAT_DONG,KHOA',
        ];
    }

    public function messages(): array
    {
        return [
            'loai_doi_tuong.in' => 'Loại đối tượng không hợp lệ.',
            'trang_thai.in'     => 'Trạng thái chỉ được là HOAT_DONG hoặc KHOA.',
        ];
    }
}