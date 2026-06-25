<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ThemNguoiDungRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        $role = $this->input('role', 'student');

        if ($role === 'teacher') {
            return [
                'name'  => 'required|string|max:255',
                'email' => 'required|email|unique:giangvien,email',
            ];
        } else {
            return [
                'id'    => 'required|string|max:20|unique:sinhvien,ma_so_sinh_vien',
                'name'  => 'required|string|max:255',
                'email' => 'required|email|unique:sinhvien,email',
            ];
        }
    }

    public function messages(): array
    {
        return [
            'id.required'    => 'Mã số sinh viên (MSSV) không được để trống.',
            'id.string'      => 'Mã số sinh viên phải là chuỗi.',
            'id.max'         => 'Mã số sinh viên không được vượt quá 20 ký tự.',
            'id.unique'      => 'Mã số sinh viên (MSSV) này đã tồn tại trong hệ thống!',
            'name.required'  => 'Họ và tên không được để trống.',
            'name.string'    => 'Họ và tên phải là chuỗi.',
            'name.max'       => 'Họ và tên không được vượt quá 255 ký tự.',
            'email.required' => 'Email không được để trống.',
            'email.email'    => 'Email không đúng định dạng.',
            'email.unique'   => $this->input('role') === 'teacher'
                ? 'Email này đã được đăng ký bởi giảng viên khác!'
                : 'Email này đã được đăng ký bởi sinh viên khác!',
        ];
    }
}
