<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuaGiangVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        $giang_vien_id = $this->route('giang_vien_id'); // Lấy ID giảng viên từ route parameter

        return [
            'ho_ten'        => 'required|string|min:3|max:255',
            'email'         => ['required','email','max:255',Rule::unique('giangvien', 'email')->ignore($giang_vien_id, 'giang_vien_id')],
            'so_dien_thoai' => 'nullable|string|regex:/^([0-9]*)$/|size:10',
            'gioi_tinh'     => 'nullable|in:Nam,Nu,Khac',
            'ngay_sinh'     => 'nullable|date',
            'hoc_vi'        => 'nullable|string|max:50',
            'chuyen_mon'    => 'required|string|max:255',
            'vai_tro'       => 'nullable|in:GIANG_VIEN,ADMIN',
        ];
    }

    public function messages(): array
    {
        return [
            'ho_ten.required'     => 'Họ và tên giảng viên không được để trống.',
            'ho_ten.min'          => 'Họ tên quá ngắn, vui lòng nhập đầy đủ.',
            'ho_ten.max'          => 'Họ tên không được vượt quá 255 ký tự.',

            'email.required'      => 'Email giảng viên là bắt buộc.',
            'email.email'         => 'Định dạng email không hợp lệ.',
            'email.max'           => 'Email không được vượt quá 255 ký tự.',
            'email.unique'        => 'Email này đã tồn tại trên hệ thống.',

            'so_dien_thoai.size'  => 'Số điện thoại phải đúng 10 chữ số.',
            'so_dien_thoai.regex' => 'Số điện thoại không hợp lệ.',

            'gioi_tinh.in'        => 'Giới tính phải là: Nam, Nu hoặc Khac.',
            'ngay_sinh.date'      => 'Ngày sinh phải đúng định dạng ngày tháng.',

            'chuyen_mon.required' => 'Vui lòng nhập chuyên môn giảng dạy.',
            'chuyen_mon.max'      => 'Nội dung chuyên môn tối đa 255 ký tự.',

            'vai_tro.in'          => 'Vai trò chỉ được là GIANG_VIEN hoặc ADMIN.'
        ];
    }
}