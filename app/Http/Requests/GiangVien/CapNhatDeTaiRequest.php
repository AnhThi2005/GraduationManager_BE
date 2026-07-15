<?php

namespace App\Http\Requests\GiangVien;

use Illuminate\Foundation\Http\FormRequest;

class CapNhatDeTaiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('GIANG_VIEN');
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:255|unique:detai,ten_de_tai,' . $this->route('id') . ',de_tai_id',
            'slots' => 'sometimes|required|string',
            'description' => 'sometimes|required|string|max:5000',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Tên đề tài không được để trống.',
            'name.string' => 'Tên đề tài phải là chuỗi.',
            'name.max' => 'Tên đề tài không được vượt quá 255 ký tự.',
            'name.unique' => 'Tên đề tài này đã tồn tại trên hệ thống.',
            'slots.required' => 'Số lượng thành viên tối đa không được để trống.',
            'description.required' => 'Mô tả đề tài không được để trống.',
            'description.max' => 'Mô tả đề tài không được vượt quá 5000 ký tự.',
        ];
    }
}
