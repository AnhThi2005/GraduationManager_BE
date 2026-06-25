<?php

namespace App\Http\Requests\Admin\QuanLySinhVienThucTap;

use Illuminate\Foundation\Http\FormRequest;

class LocDangKyThucTapRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'periodId'    => 'nullable|integer',
            'dot_id'      => 'nullable|integer',
            'keyword'     => 'nullable|string',
            'companyName' => 'nullable|string',
            'status'      => 'nullable|string',
            'trang_thai'  => 'nullable|string',
            'per_page'    => 'nullable|integer'
        ];
    }
}
