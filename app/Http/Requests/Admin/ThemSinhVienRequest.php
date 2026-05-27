<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class ThemSinhVienRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasRole('ADMIN');
    }

    public function rules(): array
    {
        return [
            'ma_so_sinh_vien' => 'required|string|max:10|unique:sinhvien,ma_so_sinh_vien',
            'ho_ten'          => 'required|string|min:3|max:255',
            'email'           => 'required|email|max:255|unique:sinhvien,email',
            'so_dien_thoai'   => 'nullable|string|regex:/^([0-9\s\-\+\(\)]*)$/|size:10', // Chuẩn hóa kiểm tra số điện thoại 10 số
            'gioi_tinh'       => 'nullable|in:Nam,Nu,Khac',
            'ngay_sinh'       => 'nullable|date',
            'lop'             => 'required|string|max:50',
            'bac_dao_tao'     => 'required|in:CAO_DANG,CAO_DANG_NGHE',
            'khoa_hoc'        => 'required|string|max:10',
            'chuyen_nganh'    => 'nullable|string|max:100',
            'dang_hoat_dong'  => 'nullable|boolean', // Đổi thành nullable để có thể sử dụng giá trị mặc định từ Model nếu không được gửi lên từ client
        ];
    }

    public function messages(): array
    {
        return [
            // Mã số sinh viên
            'ma_so_sinh_vien.required' => 'Mã số sinh viên không được để trống.',
            'ma_so_sinh_vien.unique'   => 'Mã số sinh viên này đã tồn tại trên hệ thống.',
            'ma_so_sinh_vien.max'      => 'Mã số sinh viên không được vượt quá 10 ký tự.',

            // Họ tên & Email
            'ho_ten.required'          => 'Vui lòng nhập họ và tên sinh viên.',
            'ho_ten.min'               => 'Họ tên quá ngắn, vui lòng nhập đầy đủ.',
            'email.required'           => 'Email là trường bắt buộc.',
            'email.email'              => 'Định dạng email không hợp lệ.',
            'email.unique'             => 'Email này đã được sử dụng bởi tài khoản khác.',

            // Số điện thoại
            'so_dien_thoai.size'       => 'Số điện thoại bắt buộc phải đúng 10 chữ số.',
            'so_dien_thoai.regex'      => 'Số điện thoại không được chứa ký tự chữ.',

            // Các trường bắt buộc khác
            'lop.required'             => 'Lớp học là bắt buộc.',
            'bac_dao_tao.required'     => 'Vui lòng chọn bậc đào tạo (Cao đẳng hoặc Cao đẳng nghề).',
            'bac_dao_tao.in'           => 'Bậc đào tạo đã chọn không hợp lệ.',
            'khoa_hoc.required'        => 'Vui lòng nhập khóa học (Ví dụ: K22).',
            'gioi_tinh.in'             => 'Giới tính phải là: Nam, Nu hoặc Khac.',
            'ngay_sinh.date'           => 'Ngày sinh phải đúng định dạng ngày tháng.',
            'dang_hoat_dong.boolean'   => 'Trạng thái hoạt động phải là dạng Boolean (0 hoặc 1).'
        ];
    }
}