<?php

namespace App\Http\Requests\Admin\QuanLyNguoiDung;

use Illuminate\Foundation\Http\FormRequest;

class LocSinhVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'ho_ten'          => 'nullable|string|max:255',
            'ma_so_sinh_vien' => 'nullable|string|max:20',
            'lop_id'          => 'nullable|integer',
            'ten_lop'         => 'nullable|string|max:50',
            // 'per_page' sẽ được xử lý trong prepareForValidation để đặt giá trị mặc định  
            'per_page'        => 'nullable|integer|min:1|max:100',
        ];
    }

    /**
     * Đặt giá trị mặc định cho per_page = 20
     */
    protected function prepareForValidation()
    {
        $this->merge([
            'per_page' => $this->per_page ?? 20,
        ]);
    }

    public function messages(): array
    {
        return [
            'per_page.min' => 'Số lượng trên trang tối thiểu là 1.',
            'per_page.max' => 'Số lượng trên trang tối đa là 100.',
        ];
    }
}