<?php

namespace App\Services;

use App\Models\Lop;
use App\Models\SinhVien;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;

class LopService
{
    /**
     * Lấy danh sách lớp học
     */
    public function getListClass($periodId = null)
    {
        $query = Lop::query();

        if (! empty($periodId) && $periodId !== 'all') {
            $query->whereHas('dots', function ($q) use ($periodId) {
                $q->where('dot.dot_id', $periodId);
            });
        }

        $classes = $query->with('sinhViens')->get();

        $rows = $classes->map(function ($lop) {
            return $this->transformClass($lop);
        })->all();

        return [
            'rows' => $rows,
            'total' => count($rows),
        ];
    }

    /**
     * Xem chi tiết lớp học
     */
    public function getClassDetail($id)
    {
        $lop = Lop::with('sinhViens')->find($id);
        if (! $lop) {
            return null;
        }

        return $this->transformClass($lop);
    }

    /**
     * Tạo lớp học mới
     */
    public function createClass(array $data)
    {
        // ten_lop là trường duy nhất trong DB, map với name hoặc code từ FE
        $tenLop = $data['name'] ?? $data['code'] ?? '';

        $lop = Lop::create([
            'ten_lop' => $tenLop,
            'bac_dao_tao' => $this->mapFrontendLevelToBackend($data['level'] ?? ''),
            'khoa_hoc' => $data['course'] ?? '',
            'chuyen_nganh' => $data['major'] ?? '',
            'student_list_url' => $data['studentListUrl'] ?? null,
            'student_list_filename' => $data['studentListFileName'] ?? null,
        ]);

        $lopId = $lop->lop_id;

        // 1. Nhập sinh viên từ file Excel/CSV nếu có
        if (! empty($data['studentListUrl'])) {
            $this->importStudentsFromUrl($lopId, $data['studentListUrl']);
        }

        // 2. Nhập sinh viên từ danh sách thủ công gửi kèm
        if (! empty($data['members']) && is_array($data['members'])) {
            $this->importManualMembers($lopId, $data['members']);
        }

        return $this->getClassDetail($lopId);
    }

    /**
     * Cập nhật lớp học
     */
    public function updateClass($id, array $data)
    {
        $lop = Lop::find($id);
        if (! $lop) {
            return null;
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['ten_lop'] = $data['name'];
        } elseif (isset($data['code'])) {
            $updateData['ten_lop'] = $data['code'];
        }

        if (isset($data['level'])) {
            $updateData['bac_dao_tao'] = $this->mapFrontendLevelToBackend($data['level']);
        }
        if (isset($data['course'])) {
            $updateData['khoa_hoc'] = $data['course'];
        }
        if (isset($data['major'])) {
            $updateData['chuyen_nganh'] = $data['major'];
        }
        if (isset($data['studentListUrl'])) {
            $updateData['student_list_url'] = $data['studentListUrl'];
        }
        if (isset($data['studentListFileName'])) {
            $updateData['student_list_filename'] = $data['studentListFileName'];
        }

        $lop->update($updateData);

        // 1. Nhập sinh viên từ file Excel/CSV nếu được cập nhật mới
        if (! empty($data['studentListUrl'])) {
            $this->importStudentsFromUrl($id, $data['studentListUrl']);
        }

        // 2. Đồng bộ sinh viên thủ công nếu gửi kèm
        if (isset($data['members']) && is_array($data['members'])) {
            $this->importManualMembers($id, $data['members']);
        }

        return $this->getClassDetail($id);
    }

    /**
     * Xóa lớp học
     */
    public function deleteClass($id)
    {
        $lop = Lop::find($id);
        if (! $lop) {
            return false;
        }

        // Gỡ bỏ sinh viên thuộc lớp này (set lop_id = null) thay vì xóa sinh viên để tránh mất dữ liệu liên quan
        SinhVien::where('lop_id', $id)->update(['lop_id' => null]);

        $lop->delete();

        return true;
    }

    // ==========================================================
    // HELPER METHODS
    // ==========================================================

    /**
     * Map bản ghi Lop sang cấu trúc Frontend mong đợi
     */
    private function transformClass($lop)
    {
        $members = $lop->sinhViens->map(function ($sv) {
            return [
                'id' => (string) $sv->sinh_vien_id,
                'name' => $sv->ho_ten,
                'code' => $sv->ma_so_sinh_vien,
            ];
        })->all();

        return [
            'id' => (string) $lop->lop_id,
            'code' => $lop->ten_lop,
            'name' => $lop->ten_lop,
            'level' => $this->mapBackendLevelToFrontend($lop->bac_dao_tao),
            'course' => $lop->khoa_hoc ?? '',
            'major' => $lop->chuyen_nganh ?? '',
            'supervisor' => 'TS. Nguyễn Văn A', // Mock supervisor teacher
            'members' => $members,
            'maxStudents' => count($members) + 10,
            'status' => 'ACTIVE',
            'studentListUrl' => $lop->student_list_url,
            'studentListFileName' => $lop->student_list_filename,
        ];
    }

    /**
     * Map trình độ đào tạo từ FE sang BE
     */
    private function mapFrontendLevelToBackend($level)
    {
        $levelUpper = mb_strtoupper($level);
        if (str_contains($levelUpper, 'NGHỀ')) {
            return 'CAO_DANG_NGHE';
        }

        return 'CAO_DANG';
    }

    /**
     * Map trình độ đào tạo từ BE sang FE
     */
    private function mapBackendLevelToFrontend($level)
    {
        if ($level === 'CAO_DANG_NGHE') {
            return 'Cao đẳng nghề';
        }

        return 'Cao đẳng';
    }

    /**
     * Import sinh viên từ danh sách thủ công (FE Form)
     */
    private function importManualMembers($lopId, array $members)
    {
        foreach ($members as $member) {
            $mssv = $member['code'] ?? '';
            $name = $member['name'] ?? '';

            if (empty($mssv) || empty($name)) {
                continue;
            }

            $email = strtolower($mssv).'@caothang.edu.vn';

            SinhVien::updateOrCreate(
                ['ma_so_sinh_vien' => $mssv],
                [
                    'ho_ten' => $name,
                    'email' => $email,
                    'lop_id' => $lopId,
                    'dang_hoat_dong' => 1,
                ]
            );
        }
    }

    /**
     * Tải & phân tích danh sách sinh viên từ file Excel
     */
    private function importStudentsFromUrl($lopId, $fileUrl)
    {
        try {
            // Xác định đường dẫn file cục bộ dựa trên URL
            $localPath = null;
            $parsedUrl = parse_url($fileUrl);
            $path = $parsedUrl['path'] ?? '';

            // Nếu file upload nằm ở thư mục uploads cục bộ
            if (str_contains($path, '/uploads/')) {
                $filename = basename($path);
                $localPath = public_path('uploads/'.$filename);
            }

            if (! $localPath || ! file_exists($localPath)) {
                Log::warning('Class Import: local file path not found for URL: '.$fileUrl);

                return;
            }

            $spreadsheet = IOFactory::load($localPath);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (count($rows) <= 1) {
                return; // File trống hoặc chỉ có tiêu đề
            }

            // Tìm hàng tiêu đề và các chỉ số cột phù hợp
            $headerRowIndex = 0;
            $mssvCol = 0;
            $nameCol = 1;
            $emailCol = -1;
            $phoneCol = -1;
            $genderCol = -1;

            // Quét 3 dòng đầu để tìm dòng tiêu đề
            for ($i = 0; $i < min(3, count($rows)); $i++) {
                foreach ($rows[$i] as $colIndex => $cellValue) {
                    $cellClean = mb_strtolower(trim($cellValue));
                    if (str_contains($cellClean, 'mssv') || str_contains($cellClean, 'mã số') || str_contains($cellClean, 'sinh viên id')) {
                        $mssvCol = $colIndex;
                        $headerRowIndex = $i;
                    }
                    if (str_contains($cellClean, 'họ tên') || str_contains($cellClean, 'tên') || str_contains($cellClean, 'name')) {
                        $nameCol = $colIndex;
                        $headerRowIndex = $i;
                    }
                    if (str_contains($cellClean, 'email')) {
                        $emailCol = $colIndex;
                    }
                    if (str_contains($cellClean, 'điện thoại') || str_contains($cellClean, 'sđt') || str_contains($cellClean, 'phone')) {
                        $phoneCol = $colIndex;
                    }
                    if (str_contains($cellClean, 'giới tính') || str_contains($cellClean, 'gender')) {
                        $genderCol = $colIndex;
                    }
                }
            }

            // Import từng hàng từ dưới dòng tiêu đề
            for ($rowIndex = $headerRowIndex + 1; $rowIndex < count($rows); $rowIndex++) {
                $row = $rows[$rowIndex];
                $mssv = isset($row[$mssvCol]) ? trim($row[$mssvCol]) : '';
                $name = isset($row[$nameCol]) ? trim($row[$nameCol]) : '';

                if (empty($mssv) || empty($name)) {
                    continue;
                }

                $email = ($emailCol >= 0 && ! empty($row[$emailCol])) ? trim($row[$emailCol]) : (strtolower($mssv).'@caothang.edu.vn');
                $phone = ($phoneCol >= 0 && ! empty($row[$phoneCol])) ? trim($row[$phoneCol]) : null;

                $gender = 'Nam';
                if ($genderCol >= 0 && ! empty($row[$genderCol])) {
                    $genderVal = mb_strtolower(trim($row[$genderCol]));
                    if (str_contains($genderVal, 'nữ') || str_contains($genderVal, 'female')) {
                        $gender = 'Nữ';
                    }
                }

                SinhVien::updateOrCreate(
                    ['ma_so_sinh_vien' => $mssv],
                    [
                        'ho_ten' => $name,
                        'email' => $email,
                        'so_dien_thoai' => $phone,
                        'gioi_tinh' => $gender,
                        'lop_id' => $lopId,
                        'dang_hoat_dong' => 1,
                    ]
                );
            }
        } catch (\Exception $e) {
            Log::error('Class Import Error: '.$e->getMessage());
        }
    }
}
