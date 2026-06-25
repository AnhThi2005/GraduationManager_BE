<?php

namespace App\Http\Requests\Admin\QuanLyNguoiDung;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SuaSinhVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        $sinh_vien_id = $this->route('sinh_vien_id'); // Lấy ID sinh viên từ route parameter

        return [
            'ma_so_sinh_vien' => [
                'required',
                'string',
                'min:8',
                'max:10',
                Rule::unique('sinhvien', 'ma_so_sinh_vien')->ignore($sinh_vien_id, 'sinh_vien_id')
            ],
            'ho_ten'          => 'required|string|min:3|max:255',
            'email'           => [
                'required',
                'email',
                'max:255',
                Rule::unique('sinhvien', 'email')->ignore($sinh_vien_id, 'sinh_vien_id')
            ],
            'so_dien_thoai'   => 'nullable|string|regex:/^([0-9]*)$/|size:10',
            'gioi_tinh'       => 'nullable|in:Nam,Nu,Khac',
            'ngay_sinh'       => 'nullable|date',
            'lop_id'          => 'required|integer|exists:lop,lop_id',
        ];
    }

    public function messages(): array
    {
        return [
            'ma_so_sinh_vien.required' => 'Mã số sinh viên không được để trống.',
            'ma_so_sinh_vien.min'      => 'Mã số sinh viên phải có ít nhất 8 ký tự.',
            'ma_so_sinh_vien.max'      => 'Mã số sinh viên không được vượt quá 10 ký tự.',
            'ma_so_sinh_vien.unique'   => 'Mã số sinh viên này đã tồn tại trên hệ thống.',

            'ho_ten.required'          => 'Vui lòng nhập họ và tên sinh viên.',
            'ho_ten.min'               => 'Họ tên quá ngắn, vui lòng nhập đầy đủ.',

            'email.required'           => 'Email sinh viên là bắt buộc.',
            'email.email'              => 'Định dạng email không hợp lệ.',
            'email.unique'             => 'Email này đã được sử dụng bởi tài khoản khác.',

            'so_dien_thoai.size'       => 'Số điện thoại phải đúng 10 chữ số.',
            'so_dien_thoai.regex'      => 'Số điện thoại không hợp lệ.',

            'gioi_tinh.in'             => 'Giới tính phải là: Nam, Nu hoặc Khac.',
            'ngay_sinh.date'           => 'Ngày sinh phải đúng định dạng ngày tháng.',
            'lop_id.required'          => 'Vui lòng chọn lớp học.',
        ];
    }
}