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

        $classes = $query->orderBy('lop_id', 'desc')->with('sinhViens')->get();

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
     * Lấy danh sách các trường phân loại duy nhất từ database phục vụ dropdown/autocomplete
     */
    public function getClassMetadata()
    {
        $names = Lop::whereNotNull('ten_lop')->where('ten_lop', '!=', '')->distinct()->pluck('ten_lop')->all();
        $courses = Lop::whereNotNull('khoa_hoc')->where('khoa_hoc', '!=', '')->distinct()->pluck('khoa_hoc')->all();
        $majors = Lop::whereNotNull('chuyen_nganh')->where('chuyen_nganh', '!=', '')->distinct()->pluck('chuyen_nganh')->all();
        
        $levelsRaw = Lop::whereNotNull('bac_dao_tao')->where('bac_dao_tao', '!=', '')->distinct()->pluck('bac_dao_tao')->all();
        $levels = collect($levelsRaw)->map(fn($level) => $this->mapBackendLevelToFrontend($level))->unique()->values()->all();

        return [
            'names' => $names,
            'courses' => $courses,
            'majors' => $majors,
            'levels' => $levels,
        ];
    }

    /**
     * Kiểm tra chéo trùng lặp giữa file Excel, danh sách nhập tay và dữ liệu có sẵn trong database
     */
    private function validateClassMembersBeforeImport($lopId, array $manualMembers, $studentListUrl)
    {
        $errors = [];
        $excelMssvs = [];
        $manualMssvs = [];

        // 1. Kiểm tra dữ liệu trong file Excel
        if (!empty($studentListUrl)) {
            try {
                $parsedUrl = parse_url($studentListUrl);
                $path = $parsedUrl['path'] ?? '';
                $localPath = null;

                if (str_contains($path, '/uploads/')) {
                    $filename = basename($path);
                    $localPath = public_path('uploads/'.$filename);
                }

                if ($localPath && file_exists($localPath)) {
                    $spreadsheet = IOFactory::load($localPath);
                    $worksheet = $spreadsheet->getActiveSheet();
                    $rows = $worksheet->toArray();

                    if (count($rows) > 1) {
                        $headerRowIndex = 0;
                        $mssvCol = 0;
                        $nameCol = 1;
                        $emailCol = -1;
                        $phoneCol = -1;
                        $dobCol = -1;

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
                                if (str_contains($cellClean, 'ngày sinh') || str_contains($cellClean, 'ngaysinh') || str_contains($cellClean, 'ngay_sinh') || str_contains($cellClean, 'birth') || str_contains($cellClean, 'dob')) {
                                    $dobCol = $colIndex;
                                }
                            }
                        }

                        for ($rowIndex = $headerRowIndex + 1; $rowIndex < count($rows); $rowIndex++) {
                            $row = $rows[$rowIndex];
                            $mssv = isset($row[$mssvCol]) ? trim($row[$mssvCol]) : '';
                            $name = isset($row[$nameCol]) ? trim($row[$nameCol]) : '';

                            if (empty($mssv) || empty($name)) {
                                continue;
                            }

                            // Validate MSSV format: must be 10 digits starting with 0
                            if (!preg_match('/^0[0-9]{9}$/', $mssv)) {
                                $errors[] = "File Excel - Dòng " . ($rowIndex + 1) . ": MSSV '$mssv' không hợp lệ (phải gồm 10 chữ số bắt đầu bằng số 0).";
                            }

                            // Validate Email (if present) matches expected student email
                            if ($emailCol >= 0 && isset($row[$emailCol]) && trim($row[$emailCol]) !== '') {
                                $emailVal = strtolower(trim($row[$emailCol]));
                                $expectedEmail = strtolower($mssv) . '@caothang.edu.vn';
                                if ($emailVal !== $expectedEmail) {
                                    $errors[] = "File Excel - Dòng " . ($rowIndex + 1) . ": Email '$emailVal' không khớp với MSSV '$mssv' (phải là '$expectedEmail').";
                                }
                            }

                            // Validate Phone (if present): must be 10 digits starting with 0
                            if ($phoneCol >= 0 && isset($row[$phoneCol]) && trim($row[$phoneCol]) !== '') {
                                $phoneVal = trim($row[$phoneCol]);
                                if (!preg_match('/^0[0-9]{9}$/', $phoneVal)) {
                                    $errors[] = "File Excel - Dòng " . ($rowIndex + 1) . ": Số điện thoại '$phoneVal' không hợp lệ (phải gồm 10 chữ số bắt đầu bằng số 0).";
                                }
                            }

                            // Validate Ngày sinh (if present)
                            if ($dobCol >= 0 && isset($row[$dobCol]) && trim($row[$dobCol]) !== '') {
                                $dobVal = trim($row[$dobCol]);
                                $parsedDob = $this->parseExcelDate($dobVal, $worksheet, $rowIndex, $dobCol);
                                if (!$parsedDob) {
                                    $errors[] = "File Excel - Dòng " . ($rowIndex + 1) . ": Ngày sinh '$dobVal' không hợp lệ (phải đúng định dạng ngày tháng như dd/mm/yyyy hoặc yyyy-mm-dd).";
                                }
                            }

                            // Trùng lặp nội bộ trong file Excel
                            if (in_array($mssv, $excelMssvs)) {
                                $errors[] = "File Excel - Dòng " . ($rowIndex + 1) . ": MSSV '$mssv' bị trùng lặp trong file.";
                                continue;
                            }
                            $excelMssvs[] = $mssv;
                        }
                    }
                }
            } catch (\Exception $e) {
                Log::error('Validation Excel Error: ' . $e->getMessage());
                $errors[] = "Không thể đọc hoặc phân tích file Excel: " . $e->getMessage();
            }
        }

        // 2. Kiểm tra dữ liệu nhập thủ công
        foreach ($manualMembers as $index => $member) {
            $mssv = $member['code'] ?? '';
            $name = $member['name'] ?? '';
            $phone = $member['phone'] ?? '';
            $dob = $member['dateOfBirth'] ?? '';

            if (empty($mssv) || empty($name)) {
                continue;
            }

            // Validate MSSV format
            if (!preg_match('/^0[0-9]{9}$/', $mssv)) {
                $errors[] = "Danh sách thủ công - Hàng " . ($index + 1) . ": MSSV '$mssv' không hợp lệ (phải gồm 10 chữ số bắt đầu bằng số 0).";
            }

            // Validate Phone format
            if (!empty($phone)) {
                if (!preg_match('/^0[0-9]{9}$/', trim($phone))) {
                    $errors[] = "Danh sách thủ công - Hàng " . ($index + 1) . ": Số điện thoại '$phone' không hợp lệ (phải gồm 10 chữ số bắt đầu bằng số 0).";
                }
            }

            // Validate Date of Birth format
            if (!empty($dob)) {
                try {
                    $parsedDob = \Carbon\Carbon::parse($dob);
                    if ($parsedDob->diffInYears(\Carbon\Carbon::now()) < 18) {
                        $errors[] = "Danh sách thủ công - Hàng " . ($index + 1) . ": Sinh viên phải từ 18 tuổi trở lên.";
                    }
                } catch (\Exception $e) {
                    $errors[] = "Danh sách thủ công - Hàng " . ($index + 1) . ": Ngày sinh không hợp lệ.";
                }
            }

            // Trùng lặp nội bộ trong danh sách thủ công hoặc trùng với file Excel
            if (in_array($mssv, $manualMssvs) || in_array($mssv, $excelMssvs)) {
                $errors[] = "Danh sách thủ công - Hàng " . ($index + 1) . ": MSSV '$mssv' bị trùng lặp (đã tồn tại trong file Excel hoặc danh sách nhập tay).";
                continue;
            }
            $manualMssvs[] = $mssv;
        }

        // 3. Kiểm tra chéo toàn bộ danh sách MSSV độc bản xem có trùng với lớp khác trong hệ thống không
        $allUniqueMssvs = array_unique(array_merge($excelMssvs, $manualMssvs));
        foreach ($allUniqueMssvs as $mssv) {
            $existingSv = SinhVien::where('ma_so_sinh_vien', $mssv)->first();
            if ($existingSv && $existingSv->lop_id !== null && $existingSv->lop_id != $lopId) {
                $tenLopHienTai = $existingSv->lop ? $existingSv->lop->ten_lop : 'lớp khác';
                $errors[] = "Sinh viên có MSSV '$mssv' đã tồn tại trong hệ thống (thuộc lớp $tenLopHienTai).";
            }
        }

        if (!empty($errors)) {
            throw new \InvalidArgumentException("Dữ liệu nhập vào không hợp lệ:\n" . implode("\n", $errors));
        }
    }

    /**
     * Tạo lớp học mới
     */
    public function createClass(array $data)
    {
        return DB::transaction(function () use ($data) {
            // 1. Kiểm tra tính hợp lệ của danh sách sinh viên trước khi tạo
            $this->validateClassMembersBeforeImport(0, $data['members'] ?? [], $data['studentListUrl'] ?? null);

            $tenLop = $data['name'] ?? '';

            $lop = Lop::create([
                'ten_lop' => $tenLop,
                'bac_dao_tao' => $this->mapFrontendLevelToBackend($data['level'] ?? ''),
                'khoa_hoc' => $data['course'] ?? '',
                'chuyen_nganh' => $data['major'] ?? '',
                'student_list_url' => $data['studentListUrl'] ?? null,
                'student_list_filename' => $data['studentListFileName'] ?? null,
            ]);

            $lopId = $lop->lop_id;

            // Associate with period if provided
            $periodId = $data['periodId'] ?? $data['period_id'] ?? null;
            if (!empty($periodId) && $periodId !== 'all') {
                $lop->dots()->attach($periodId);
            }

            // 2. Nhập sinh viên từ file Excel/CSV nếu có
            if (! empty($data['studentListUrl'])) {
                $this->importStudentsFromUrl($lopId, $data['studentListUrl']);
            }

            // 3. Nhập sinh viên từ danh sách thủ công gửi kèm
            if (! empty($data['members']) && is_array($data['members'])) {
                $this->importManualMembers($lopId, $data['members']);
            }

            // 4. Kiểm tra số lượng sinh viên tối thiểu
            $totalCount = SinhVien::where('lop_id', $lopId)->count();
            if ($totalCount < 1) {
                throw new \InvalidArgumentException("Lớp học phải có ít nhất 1 sinh viên mới tạo được!");
            }

            return $this->getClassDetail($lopId);
        });
    }

    /**
     * Cập nhật lớp học
     */
    public function updateClass($id, array $data)
    {
        return DB::transaction(function () use ($id, $data) {
            $lop = Lop::find($id);
            if (! $lop) {
                return null;
            }

            // 1. Kiểm tra tính hợp lệ của danh sách sinh viên trước khi cập nhật
            $this->validateClassMembersBeforeImport($id, $data['members'] ?? [], $data['studentListUrl'] ?? null);

            $updateData = [];
            if (isset($data['name'])) {
                $updateData['ten_lop'] = $data['name'];
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

            // 2. Nhập sinh viên từ file Excel/CSV nếu được cập nhật mới
            if (! empty($data['studentListUrl'])) {
                $this->importStudentsFromUrl($id, $data['studentListUrl']);
            }

            // 3. Đồng bộ sinh viên thủ công nếu gửi kèm
            if (isset($data['members']) && is_array($data['members'])) {
                $this->importManualMembers($id, $data['members']);
            }

            // 4. Kiểm tra số lượng sinh viên tối thiểu
            $totalCount = SinhVien::where('lop_id', $id)->count();
            if ($totalCount < 1) {
                throw new \InvalidArgumentException("Lớp học phải có ít nhất 1 sinh viên!");
            }

            return $this->getClassDetail($id);
        });
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
            'name' => $lop->ten_lop,
            'level' => $this->mapBackendLevelToFrontend($lop->bac_dao_tao),
            'course' => $lop->khoa_hoc ?? '',
            'major' => $lop->chuyen_nganh ?? '',
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
            $phone = !empty($member['phone']) ? trim($member['phone']) : null;
            $gender = !empty($member['gender']) ? trim($member['gender']) : 'Nam';
            $dob = null;
            if (!empty($member['dateOfBirth'])) {
                try {
                    $dob = \Carbon\Carbon::parse($member['dateOfBirth'])->format('Y-m-d');
                } catch (\Exception $e) {
                    $dob = null;
                }
            }

            if (empty($mssv) || empty($name)) {
                continue;
            }

            $email = strtolower($mssv).'@caothang.edu.vn';

            SinhVien::updateOrCreate(
                ['ma_so_sinh_vien' => $mssv],
                [
                    'ho_ten' => $name,
                    'email' => $email,
                    'so_dien_thoai' => $phone,
                    'gioi_tinh' => $gender,
                    'ngay_sinh' => $dob,
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
            $dobCol = -1;

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
                    if (str_contains($cellClean, 'ngày sinh') || str_contains($cellClean, 'ngaysinh') || str_contains($cellClean, 'ngay_sinh') || str_contains($cellClean, 'birth') || str_contains($cellClean, 'dob')) {
                        $dobCol = $colIndex;
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

                $dob = null;
                if ($dobCol >= 0 && ! empty($row[$dobCol])) {
                    $dob = $this->parseExcelDate(trim($row[$dobCol]), $worksheet, $rowIndex, $dobCol);
                }

                SinhVien::updateOrCreate(
                    ['ma_so_sinh_vien' => $mssv],
                    [
                        'ho_ten' => $name,
                        'email' => $email,
                        'so_dien_thoai' => $phone,
                        'gioi_tinh' => $gender,
                        'ngay_sinh' => $dob,
                        'lop_id' => $lopId,
                        'dang_hoat_dong' => 1,
                    ]
                );
            }
        } catch (\Exception $e) {
            Log::error('Class Import Error: '.$e->getMessage());
        }
    }

    /**
     * Parse date from Excel cell
     */
    private function parseExcelDate($val, $worksheet, $rowIndex, $colIndex)
    {
        if (empty($val)) {
            return null;
        }

        try {
            $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colIndex + 1);
            $cell = $worksheet->getCell($colLetter . ($rowIndex + 1));
            if (\PhpOffice\PhpSpreadsheet\Shared\Date::isDateTime($cell)) {
                $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($cell->getValue());
                return $dateTime->format('Y-m-d');
            }
        } catch (\Exception $e) {
            // Ignore and fall back to string parsing
        }

        $val = trim($val);
        
        $dmyPattern = '/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/';
        if (preg_match($dmyPattern, $val, $matches)) {
            $day = (int)$matches[1];
            $month = (int)$matches[2];
            $year = (int)$matches[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        $ymdPattern = '/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/';
        if (preg_match($ymdPattern, $val, $matches)) {
            $year = (int)$matches[1];
            $month = (int)$matches[2];
            $day = (int)$matches[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }

        try {
            return \Carbon\Carbon::parse($val)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }
}
