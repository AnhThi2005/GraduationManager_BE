<?php

namespace App\Http\Requests\Admin\QuanLyLop;

use Illuminate\Foundation\Http\FormRequest;

class CapNhatLopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->tokenCan('ADMIN');
    }

    public function rules(): array
    {
        return [
            'name' => [
                'sometimes',
                'string',
                'regex:/^(CĐ|CĐN|CDN|cđ|cđn|cdn)\s+(TH\s+)?\d{2}[a-zA-ZĐđ0-9]+$/',
                'unique:lop,ten_lop,' . $this->route('id') . ',lop_id'
            ],
            'level' => 'sometimes|string|in:Cao đẳng,Cao đẳng nghề,CAO_DANG,CAO_DANG_NGHE',
            'course' => 'sometimes|regex:/^[1-9]\d{3}$/',
            'major' => [
                'sometimes',
                'string',
                'in:Lập trình di động,Lập trình Web,Mạng máy tính,Công nghệ phần mềm,lập trình di động,lập trình web,mạng máy tính,công nghệ phần mềm,LẬP TRÌNH DI ĐỘNG,LẬP TRÌNH WEB,MẠNG MÁY TÍNH,CÔNG NGHỆ PHẦN MỀM'
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'Tên lớp phải là chuỗi.',
            'name.regex' => 'Tên lớp phải đúng định dạng (Ví dụ: CĐ TH 23DĐD, CĐ TH 21DĐ).',
            'name.unique' => 'Tên lớp này đã tồn tại trong hệ thống.',
            'level.in' => 'Bậc đào tạo phải là "Cao đẳng" hoặc "Cao đẳng nghề".',
            'course.regex' => 'Khóa học phải là năm học hợp lệ gồm 4 chữ số (Ví dụ: 2023).',
            'major.in' => 'Chuyên ngành không hợp lệ. Vui lòng chọn các chuyên ngành: Lập trình di động, Lập trình Web, Mạng máy tính hoặc Công nghệ phần mềm.',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $classId = $this->route('id');
            $class = \App\Models\Lop::find($classId);
            if (!$class) {
                return;
            }

            $name = $this->input('name', $class->ten_lop);
            $course = $this->input('course', $class->khoa_hoc);
            $major = $this->input('major', $class->chuyen_nganh);

            if ($name && $course) {
                // Trích xuất 2 số năm trong tên lớp
                if (preg_match('/(?:CĐ|CĐN|CDN|cđ|cđn|cdn)\s+(?:TH\s+)?(\d{2})/i', $name, $matches)) {
                    $classYearSuffix = $matches[1];
                    $courseYearSuffix = substr($course, -2);
                    if ($classYearSuffix !== $courseYearSuffix) {
                        $validator->errors()->add('course', "Năm học trong khóa học ($course) không khớp với niên khóa trong tên lớp ($classYearSuffix).");
                    }
                }
            }

            if ($name && $major) {
                $nameUpper = mb_strtoupper($name);
                $majorClean = mb_strtolower(trim($major));

                if (str_contains($nameUpper, 'WEB')) {
                    if ($majorClean !== 'lập trình web') {
                        $validator->errors()->add('major', 'Tên lớp chứa ký hiệu "WEB" nên chuyên ngành phải là "Lập trình Web".');
                    }
                } elseif (str_contains($nameUpper, 'MMT')) {
                    if ($majorClean !== 'mạng máy tính') {
                        $validator->errors()->add('major', 'Tên lớp chứa ký hiệu "MMT" nên chuyên ngành phải là "Mạng máy tính".');
                    }
                } elseif (str_contains($nameUpper, 'PM')) {
                    if ($majorClean !== 'công nghệ phần mềm') {
                        $validator->errors()->add('major', 'Tên lớp chứa ký hiệu "PM" nên chuyên ngành phải là "Công nghệ phần mềm".');
                    }
                } elseif (str_contains($nameUpper, 'DĐ')) {
                    if ($majorClean !== 'lập trình di động') {
                        $validator->errors()->add('major', 'Tên lớp chứa ký hiệu "DĐ" nên chuyên ngành phải là "Lập trình di động".');
                    }
                }
            }
        });
    }
}
