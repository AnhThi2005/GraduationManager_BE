<?php

namespace App\Http\Requests\Admin\QuanLyNguoiDung;

use App\Models\GiangVien;
use App\Models\SinhVien;
use Illuminate\Foundation\Http\FormRequest;

class CapNhatNguoiDungRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        $id = $this->route('id');

        // Xác định vai trò từ cơ sở dữ liệu dựa trên id được truyền lên
        $isTeacher = GiangVien::where('giang_vien_id', $id)->exists();

        if ($isTeacher) {
            return [
                'role' => 'nullable|string|in:student,teacher,admin',
                'name' => 'nullable|string|max:255',
                'email' => 'nullable|email|unique:giangvien,email,'.$id.',giang_vien_id|regex:/^[a-z]+@caothang\.edu\.vn$/',
                'phone' => 'nullable|string|regex:/^([0-9]*)$/|size:10',
                'gender' => 'nullable|in:Nam,Nu,Khac',
                'dateOfBirth' => 'nullable|date|before_or_equal:today',
                'academicDegree' => 'nullable|string|max:50',
                'specialization' => 'nullable|string|max:255',
                'className' => 'nullable|string|max:255', // fallback
                'status' => 'nullable|in:active,inactive',
            ];
        } else {
            $sv = SinhVien::where('ma_so_sinh_vien', $id)
                ->orWhere('sinh_vien_id', $id)
                ->first();
            $svDbId = $sv ? $sv->sinh_vien_id : null;

            return [
                'role' => 'nullable|string|in:student,teacher,admin',
                'name' => 'nullable|string|max:255',
                'email' => 'nullable|email|unique:sinhvien,email,'.$svDbId.',sinh_vien_id|regex:/^0[0-9]{9}@caothang\.edu\.vn$/',
                'phone' => 'nullable|string|regex:/^[0-9]+$/',
                'gender' => 'nullable|in:Nam,Nu,Khac',
                'dateOfBirth' => 'nullable|date|before_or_equal:today',
                'className' => 'nullable|string|exists:lop,ten_lop|max:50',
                'status' => 'nullable|in:active,inactive',
            ];
        }
    }

    public function messages(): array
    {
        $id = $this->route('id');
        $isTeacher = GiangVien::where('giang_vien_id', $id)->exists();

        return [
            'name.string' => 'Họ và tên phải là chuỗi.',
            'name.max' => 'Họ và tên không được vượt quá 255 ký tự.',
            'email.email' => 'Email không đúng định dạng.',
            'email.regex' => $isTeacher
                ? 'Email giảng viên không hợp lệ (phải đúng định dạng tên@caothang.edu.vn)!'
                : 'Email sinh viên không hợp lệ (phải có 10 chữ số bắt đầu bằng 0 và đuôi @caothang.edu.vn)!',
            'email.unique' => 'Email này đã tồn tại trên hệ thống.',
            'phone.regex' => 'Số điện thoại chỉ được chứa các chữ số!',
            'phone.size' => 'Số điện thoại phải có đúng 10 chữ số!',
            'gender.in' => 'Giới tính phải là: Nam, Nu hoặc Khac.',
            'dateOfBirth.date' => 'Ngày sinh không đúng định dạng ngày.',
            'dateOfBirth.before_or_equal' => 'Ngày sinh không được là ngày trong tương lai!',
            'className.exists' => 'Lớp học không tồn tại trong danh sách lớp!',
            'className.max' => 'Lớp học không được vượt quá 50 ký tự.',
            'status.in' => 'Trạng thái hoạt động phải là: active hoặc inactive.',
        ];
    }

    public function toServiceData(): array
    {
        $id = $this->route('id');
        $isTeacher = GiangVien::where('giang_vien_id', $id)->exists();
        $updateData = [];

        if ($this->has('name')) {
            $updateData['ho_ten'] = $this->input('name');
        }
        if ($this->has('email')) {
            $updateData['email'] = $this->input('email');
        }
        if ($this->has('phone')) {
            $updateData['so_dien_thoai'] = $this->input('phone');
        }
        if ($this->has('gender')) {
            $updateData['gioi_tinh'] = $this->input('gender');
        }
        if ($this->has('dateOfBirth')) {
            $updateData['ngay_sinh'] = $this->input('dateOfBirth');
        }
        if ($this->has('status')) {
            $updateData['dang_hoat_dong'] = $this->input('status') === 'active' ? 1 : 0;
        }
        if ($this->has('className')) {
            $updateData['className'] = $this->input('className');
        }

        if ($isTeacher) {
            if ($this->has('academicDegree')) {
                $updateData['hoc_vi'] = $this->input('academicDegree');
            }
            if ($this->has('specialization')) {
                $updateData['chuyen_mon'] = $this->input('specialization');
            }
            if ($this->has('role')) {
                $updateData['vai_tro'] = $this->input('role') === 'admin' ? 'ADMIN' : 'GIANG_VIEN';
            }
        }

        return $updateData;
    }
}
