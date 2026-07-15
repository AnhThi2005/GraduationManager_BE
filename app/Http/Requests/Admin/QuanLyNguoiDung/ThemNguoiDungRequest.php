<?php

namespace App\Http\Requests\Admin\QuanLyNguoiDung;

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

        if ($role === 'teacher' || $role === 'admin') {
            return [
                'role' => 'required|string|in:student,teacher,admin',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:giangvien,email',
                'phone' => 'nullable|string|regex:/^([0-9]*)$/|size:10',
                'gender' => 'nullable|in:Nam,Nu,Khac',
                'dateOfBirth' => 'nullable|date|before_or_equal:today',
                'academicDegree' => 'nullable|string|max:50',
                'specialization' => 'required|string|max:255',
                'status' => 'nullable|in:active,inactive',
            ];
        } else {
            return [
                'role' => 'required|string|in:student,teacher,admin',
                'id' => 'required|string|regex:/^0[0-9]+$/|max:10|unique:sinhvien,ma_so_sinh_vien',
                'name' => 'required|string|max:255',
                'email' => 'required|email|unique:sinhvien,email|regex:/^0[0-9]{9}@caothang\.edu\.vn$/',
                'phone' => 'nullable|string|regex:/^[0-9]+$/',
                'gender' => 'nullable|in:Nam,Nu,Khac',
                'dateOfBirth' => 'nullable|date|before_or_equal:today',
                'className' => 'required|string|exists:lop,ten_lop',
                'status' => 'nullable|in:active,inactive',
            ];
        }
    }

    public function messages(): array
    {
        return [
            'id.required' => 'Mã số sinh viên (MSSV) không được để trống.',
            'id.string' => 'Mã số sinh viên phải là chuỗi.',
            'id.regex' => 'Mã số sinh viên phải là số bắt đầu bằng số 0!',
            'id.max' => 'Mã số sinh viên không được vượt quá 10 ký tự.',
            'id.unique' => 'Mã số sinh viên (MSSV) này đã tồn tại trong hệ thống!',
            'name.required' => 'Họ và tên không được để trống.',
            'name.string' => 'Họ và tên phải là chuỗi.',
            'name.max' => 'Họ và tên không được vượt quá 255 ký tự.',
            'email.required' => 'Email không được để trống.',
            'email.email' => 'Email không đúng định dạng.',
            'email.regex' => ($this->input('role') === 'teacher' || $this->input('role') === 'admin')
                ? 'Email giảng viên không hợp lệ (phải đúng định dạng tên@caothang.edu.vn)!'
                : 'Email sinh viên không hợp lệ (phải có 10 chữ số bắt đầu bằng 0 và đuôi @caothang.edu.vn)!',
            'email.unique' => ($this->input('role') === 'teacher' || $this->input('role') === 'admin')
                ? 'Email này đã được đăng ký bởi giảng viên hoặc quản trị viên khác!'
                : 'Email này đã được đăng ký bởi sinh viên khác!',
            'phone.regex' => 'Số điện thoại chỉ được chứa các chữ số!',
            'phone.size' => 'Số điện thoại phải có đúng 10 chữ số!',
            'gender.in' => 'Giới tính phải là: Nam, Nu hoặc Khac.',
            'dateOfBirth.date' => 'Ngày sinh không đúng định dạng ngày.',
            'dateOfBirth.before_or_equal' => 'Ngày sinh không được là ngày trong tương lai!',
            'className.required' => 'Lớp học không được để trống.',
            'className.exists' => 'Lớp học không tồn tại trong danh sách lớp!',
            'className.max' => 'Lớp học không được vượt quá 50 ký tự.',
            'specialization.required' => 'Chuyên môn không được để trống.',
            'status.in' => 'Trạng thái hoạt động phải là: active hoặc inactive.',
        ];
    }

    public function toServiceData(): array
    {
        $role = $this->input('role', 'student');
        $status = $this->input('status');

        $commonData = [
            'ho_ten' => $this->input('name'),
            'email' => $this->input('email'),
            'so_dien_thoai' => $this->input('phone'),
            'gioi_tinh' => $this->input('gender'),
            'ngay_sinh' => $this->input('dateOfBirth'),
            'dang_hoat_dong' => ($status === 'inactive') ? 0 : 1,
        ];

        if ($role === 'teacher' || $role === 'admin') {
            return array_merge($commonData, [
                'hoc_vi' => $this->input('academicDegree'),
                'chuyen_mon' => $this->input('specialization'),
                'vai_tro' => ($role === 'admin') ? 'ADMIN' : 'GIANG_VIEN',
            ]);
        } else {
            return array_merge($commonData, [
                'ma_so_sinh_vien' => $this->input('id'),
                'className' => $this->input('className'),
            ]);
        }
    }
}
