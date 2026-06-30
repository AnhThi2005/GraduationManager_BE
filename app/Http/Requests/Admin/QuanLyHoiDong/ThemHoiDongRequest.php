<?php

namespace App\Http\Requests\Admin\QuanLyHoiDong;

use Illuminate\Foundation\Http\FormRequest;

class ThemHoiDongRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'room'  => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Tên hội đồng không được để trống.',
            'title.string'   => 'Tên hội đồng phải là chuỗi.',
            'room.required'  => 'Phòng bảo vệ không được để trống.',
            'room.string'    => 'Phòng bảo vệ phải là chuỗi.',
        ];
    }
}
