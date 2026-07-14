<?php

namespace App\Services;

use App\Models\DangKyThucTap;
use App\Models\DeTai;
use App\Models\Dot;
use App\Models\Nhom;
use App\Models\SinhVien;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DotService
{
    /**
     * Lấy danh sách các đợt đăng ký kèm phân trang và bộ lọc
     */
    public function getListPeriod(array $filters, $perPage = 10)
    {
        $query = Dot::query();

        // 1. Lọc theo keyword (tìm kiếm trong tên đợt, học kỳ, năm học)
        if (! empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->where('ten_dot', 'like', '%'.$keyword.'%')
                    ->orWhere('hoc_ky', 'like', '%'.$keyword.'%')
                    ->orWhere('nam_hoc', 'like', '%'.$keyword.'%');
            });
        }

        // 2. Lọc theo loại đợt (tttn / datn)
        if (! empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('loai_dot', strtoupper($filters['type']));
        }

        // 3. Lọc theo trạng thái
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $backendStatus = $this->mapFrontendStatusToBackend($filters['status']);
            $query->where('trang_thai', $backendStatus);
        }

        // Sắp xếp đợt mới nhất lên đầu
        $query->orderBy('dot_id', 'desc');

        $paginator = $query->paginate($perPage);

        $rows = collect($paginator->items())->map(function ($dot) {
            return $this->transformPeriod($dot);
        })->all();

        return [
            'rows' => $rows,
            'total' => $paginator->total(),
            'lastPage' => $paginator->lastPage(),
            'perPage' => $paginator->perPage(),
            'currentPage' => $paginator->currentPage(),
            'onFirstPage' => $paginator->onFirstPage(),
            'hasMorePages' => $paginator->hasMorePages(),
        ];
    }

    /**
     * Xem chi tiết đợt đăng ký bằng ID
     */
    public function getPeriodDetail($id)
    {
        $dot = Dot::find($id);
        if (! $dot) {
            return null;
        }

        return $this->transformPeriod($dot);
    }

    /**
     * Tạo đợt đăng ký mới
     */
    public function createPeriod(array $data)
    {
        $this->validatePeriodDatesAndSchoolYear($data);
        $loaiDot = isset($data['type']) ? strtoupper($data['type']) : 'TTTN';
        $trangThaiMoi = isset($data['status']) ? $this->mapFrontendStatusToBackend($data['status']) : 'DA_CONG_BO';
        $this->assertKhongTrungDotDangHoatDong($loaiDot, $trangThaiMoi);

        // Validation: Check if any manual student belongs to the selected classes
        if (isset($data['externalStudentIds']) && is_array($data['externalStudentIds']) && !empty($data['classIds']) && is_array($data['classIds'])) {
            $duplicateStudents = SinhVien::whereIn('ma_so_sinh_vien', $data['externalStudentIds'])
                ->whereIn('lop_id', $data['classIds'])
                ->get();
            if ($duplicateStudents->isNotEmpty()) {
                $names = $duplicateStudents->map(fn($sv) => $sv->ho_ten . ' (' . $sv->ma_so_sinh_vien . ')')->implode(', ');
                throw new \InvalidArgumentException('Sinh viên ' . $names . ' đã thuộc lớp học được chọn tham gia đợt!');
            }
        }

        $insertData = [
            'ten_dot' => $data['name'] ?? '',
            'loai_dot' => $loaiDot,
            'trang_thai' => $trangThaiMoi,
            'ngay_bat_dau' => $this->parseDate($data['startDate'] ?? null),
            'ngay_ket_thuc' => $this->parseDate($data['endDate'] ?? null),
            'han_dang_ky' => $this->parseDate($data['regDeadline'] ?? null),
            'hoc_ky' => $data['semester'] ?? 1,
            'nam_hoc' => $data['schoolYear'] ?? (date('Y').'-'.(date('Y') + 1)),
            'giang_vien_id' => $data['teacherId'] ?? 1, // Mặc định gán giảng viên tạo
        ];

        // Mốc thời gian phụ: dùng giá trị admin nhập nếu có, nếu không thì tự tính mặc định
        $insertData['ngay_bat_dau_dang_ky'] = $this->parseDate($data['regOpenDate'] ?? null)
            ?? $insertData['ngay_bat_dau'];

        if ($loaiDot === 'DATN') {
            $insertData['han_nop_bao_cao'] = $this->parseDate($data['reportDeadline'] ?? null)
                ?? Carbon::parse($insertData['ngay_ket_thuc'])->subDays(7)->format('Y-m-d');
            $insertData['ngay_bat_dau_phan_bien'] = $this->parseDate($data['reviewStartDate'] ?? null)
                ?? Carbon::parse($insertData['han_nop_bao_cao'])->addDay()->format('Y-m-d');
            $insertData['ngay_ket_thuc_phan_bien'] = $this->parseDate($data['reviewEndDate'] ?? null)
                ?? Carbon::parse($insertData['ngay_bat_dau_phan_bien'])->addDay()->format('Y-m-d');
            $insertData['ngay_bat_dau_bao_ve'] = $this->parseDate($data['defenseStartDate'] ?? null)
                ?? Carbon::parse($insertData['ngay_ket_thuc_phan_bien'])->addDay()->format('Y-m-d');
            $insertData['ngay_ket_thuc_bao_ve'] = $this->parseDate($data['defenseEndDate'] ?? null)
                ?? Carbon::parse($insertData['ngay_bat_dau_bao_ve'])->addDay()->format('Y-m-d');
            $insertData['ngay_bat_dau_cham_diem'] = $this->parseDate($data['gradingStartDate'] ?? null)
                ?? Carbon::parse($insertData['ngay_ket_thuc_bao_ve'])->addDay()->format('Y-m-d');
        } else {
            // TTTN mode
            $insertData['han_nop_bao_cao'] = $this->parseDate($data['reportDeadline'] ?? null)
                ?? Carbon::parse($insertData['ngay_ket_thuc'])->subDays(3)->format('Y-m-d');
            $insertData['ngay_bat_dau_phan_bien'] = null;
            $insertData['ngay_ket_thuc_phan_bien'] = null;
            $insertData['ngay_bat_dau_bao_ve'] = null;
            $insertData['ngay_ket_thuc_bao_ve'] = null;
            $insertData['ngay_bat_dau_cham_diem'] = $this->parseDate($data['gradingStartDate'] ?? null)
                ?? Carbon::parse($insertData['ngay_ket_thuc'])->subDays(2)->format('Y-m-d');
        }

        $insertData['ngay_ket_thuc_cham_diem'] = $this->parseDate($data['gradingEndDate'] ?? null)
            ?? Carbon::parse($insertData['ngay_ket_thuc'])->subDays(1)->format('Y-m-d');

        $dot = Dot::create($insertData);

        if (! empty($data['classIds']) && is_array($data['classIds'])) {
            $dot->lops()->sync($data['classIds']);
        }

        if (isset($data['externalStudentIds']) && is_array($data['externalStudentIds'])) {
            $studentIds = SinhVien::whereIn('ma_so_sinh_vien', $data['externalStudentIds'])
                ->pluck('sinh_vien_id')
                ->all();
            $dot->sinhViens()->sync($studentIds);
        }

        return $this->transformPeriod($dot);
    }

    /**
     * Cập nhật đợt đăng ký
     */
    public function updatePeriod($id, array $data)
    {
        $dot = Dot::find($id);
        if (! $dot) {
            return null;
        }

        $this->validatePeriodDatesAndSchoolYear($data, $dot);

        if ($dot->daKhoaHoanToan()) {
            $disallowedKeys = array_diff(array_keys($data), ['status', 'type']);
            if (! empty($disallowedKeys)) {
                throw new \InvalidArgumentException(
                    "Đợt \"{$dot->ten_dot}\" đã đóng, không thể chỉnh sửa thông tin cấu hình của đợt này nữa."
                );
            }
        }

        // Validation: Check if any manual student belongs to the selected classes
        $checkClassIds = $data['classIds'] ?? null;
        if ($checkClassIds === null) {
            $checkClassIds = $dot->lops()->pluck('lop.lop_id')->all();
        }
        $checkExternalStudentIds = $data['externalStudentIds'] ?? null;
        if ($checkExternalStudentIds === null) {
            $checkExternalStudentIds = $dot->sinhViens()->pluck('sinhvien.ma_so_sinh_vien')->all();
        }

        if (!empty($checkExternalStudentIds) && is_array($checkExternalStudentIds) && !empty($checkClassIds) && is_array($checkClassIds)) {
            $duplicateStudents = SinhVien::whereIn('ma_so_sinh_vien', $checkExternalStudentIds)
                ->whereIn('lop_id', $checkClassIds)
                ->get();
            if ($duplicateStudents->isNotEmpty()) {
                $names = $duplicateStudents->map(fn($sv) => $sv->ho_ten . ' (' . $sv->ma_so_sinh_vien . ')')->implode(', ');
                throw new \InvalidArgumentException('Sinh viên ' . $names . ' đã thuộc lớp học được chọn tham gia đợt!');
            }
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['ten_dot'] = $data['name'];
        }
        if (isset($data['type'])) {
            $updateData['loai_dot'] = strtoupper($data['type']);
        }
        if (isset($data['status'])) {
            $trangThaiMoi = $this->mapFrontendStatusToBackend($data['status']);
            $loaiDotForCheck = isset($data['type']) ? strtoupper($data['type']) : $dot->loai_dot;
            $this->assertKhongTrungDotDangHoatDong($loaiDotForCheck, $trangThaiMoi, $dot->dot_id);
            $updateData['trang_thai'] = $trangThaiMoi;
        }
        if (isset($data['startDate'])) {
            $updateData['ngay_bat_dau'] = $this->parseDate($data['startDate']);
        }
        if (isset($data['endDate'])) {
            $updateData['ngay_ket_thuc'] = $this->parseDate($data['endDate']);
        }
        if (isset($data['regDeadline'])) {
            $updateData['han_dang_ky'] = $this->parseDate($data['regDeadline']);
        }
        if (isset($data['regOpenDate'])) {
            $updateData['ngay_bat_dau_dang_ky'] = $this->parseDate($data['regOpenDate']);
        }
        if (isset($data['reportDeadline'])) {
            $updateData['han_nop_bao_cao'] = $this->parseDate($data['reportDeadline']);
        }
        if (isset($data['gradingStartDate'])) {
            $updateData['ngay_bat_dau_cham_diem'] = $this->parseDate($data['gradingStartDate']);
        }
        if (isset($data['gradingEndDate'])) {
            $updateData['ngay_ket_thuc_cham_diem'] = $this->parseDate($data['gradingEndDate']);
        }
        if ($loaiDot === 'TTTN') {
            $updateData['ngay_bat_dau_phan_bien'] = null;
            $updateData['ngay_ket_thuc_phan_bien'] = null;
            $updateData['ngay_bat_dau_bao_ve'] = null;
            $updateData['ngay_ket_thuc_bao_ve'] = null;
        } else {
            if (isset($data['reviewStartDate'])) {
                $updateData['ngay_bat_dau_phan_bien'] = $this->parseDate($data['reviewStartDate']);
            }
            if (isset($data['reviewEndDate'])) {
                $updateData['ngay_ket_thuc_phan_bien'] = $this->parseDate($data['reviewEndDate']);
            }
            if (isset($data['defenseStartDate'])) {
                $updateData['ngay_bat_dau_bao_ve'] = $this->parseDate($data['defenseStartDate']);
            }
            if (isset($data['defenseEndDate'])) {
                $updateData['ngay_ket_thuc_bao_ve'] = $this->parseDate($data['defenseEndDate']);
            }
        }
        if (isset($data['semester'])) {
            $updateData['hoc_ky'] = $data['semester'];
        }
        if (isset($data['schoolYear'])) {
            $updateData['nam_hoc'] = $data['schoolYear'];
        }

        $dot->update($updateData);

        if (isset($data['classIds']) && is_array($data['classIds'])) {
            $dot->lops()->sync($data['classIds']);
        }

        if (isset($data['externalStudentIds']) && is_array($data['externalStudentIds'])) {
            $studentIds = SinhVien::whereIn('ma_so_sinh_vien', $data['externalStudentIds'])
                ->pluck('sinh_vien_id')
                ->all();
            $dot->sinhViens()->sync($studentIds);
        }

        return $this->transformPeriod($dot->fresh());
    }

    /**
     * Xóa đợt đăng ký
     */
    public function deletePeriod($id)
    {
        $dot = Dot::find($id);
        if (! $dot) {
            return false;
        }

        if (
            DeTai::where('dot_id', $id)->exists()
            || DangKyThucTap::where('dot_id', $id)->exists()
            || Nhom::where('dot_id', $id)->exists()
        ) {
            throw new \InvalidArgumentException(
                'Không thể xóa đợt này vì đã có đề tài, khai báo thực tập hoặc nhóm đồ án gắn với đợt. Vui lòng xử lý dữ liệu liên quan trước.'
            );
        }

        $dot->delete();

        return true;
    }

    // ==========================================================
    // HELPER FUNCTIONS
    // ==========================================================

    /**
     * Đảm bảo cùng 1 loại đợt (TTTN/ĐATN) chỉ có tối đa 1 đợt ở trạng thái "chưa đóng"
     * (đang mở / đã công bố / đang chấm điểm) tại một thời điểm — các đợt còn lại cùng loại
     * phải đóng hết mới hợp logic. Nhiều đợt cùng loại cùng "hoạt động" song song chính là
     * nguyên nhân gốc của nhiều bug suy luận sai "đợt hiện tại của sinh viên" đã gặp trong dự
     * án (lớp liên kết nhiều đợt cùng lúc) — chặn ngay lúc đổi trạng thái là cách xử lý triệt để.
     */
    private function assertKhongTrungDotDangHoatDong($loaiDot, $trangThaiMoi, $excludeDotId = null)
    {
        $now = Carbon::now('Asia/Ho_Chi_Minh')->format('Y-m-d');

        $query = Dot::where('loai_dot', $loaiDot)
            ->where(function($q) use ($now) {
                $q->whereNull('ngay_ket_thuc_cham_diem')
                  ->orWhere('ngay_ket_thuc_cham_diem', '>=', $now);
            });

        if ($excludeDotId) {
            $query->where('dot_id', '!=', $excludeDotId);
        }

        $dangHoatDong = $query->first();
        if ($dangHoatDong) {
            $tenLoai = $loaiDot === 'DATN' ? 'ĐATN' : 'TTTN';
            throw new \InvalidArgumentException(
                "Đợt \"{$dangHoatDong->ten_dot}\" ({$tenLoai}) đang hoạt động (ngày kết thúc chấm điểm {$dangHoatDong->ngay_ket_thuc_cham_diem} chưa qua). Mỗi loại đợt chỉ được có tối đa 1 đợt hoạt động cùng lúc."
            );
        }
    }

    /**
     * Chuyển đổi bản ghi Database sang cấu trúc Front-End mong đợi
     */
    private function transformPeriod($dot)
    {
        $dotId = $dot->dot_id;
        $type = strtolower($dot->loai_dot);

        // 1. Tính số lượng doanh nghiệp (numberDN)
        $numberDN = DB::table('dangkythuctap')
            ->where('dot_id', $dotId)
            ->whereNotNull('cong_ty_id')
            ->distinct()
            ->count('cong_ty_id');

        // 2. Tính số lượng sinh viên tham gia (numberSV)
        if ($type === 'datn') {
            $numberSV = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.dot_id', $dotId)
                ->distinct()
                ->count('thanhviennhom.sinh_vien_id');
        } else {
            $numberSV = DB::table('dangkythuctap')
                ->where('dot_id', $dotId)
                ->distinct()
                ->count('sinh_vien_id');
        }

        // 3. Tính số đề tài (numberTopics) và số hội đồng (numberCouncils)
        $numberTopics = DB::table('detai')->where('dot_id', $dotId)->count();
        $numberCouncils = DB::table('hoidong')->where('dot_id', $dotId)->count();

        // 4. Lấy danh sách classIds tham gia đợt động (từ bảng liên kết hoặc sinh viên đã đăng ký)
        $classIds = $dot->lops()->pluck('lop.lop_id')->map(fn ($id) => (string) $id)->all();
        if (empty($classIds)) {
            if ($type === 'datn') {
                $classIds = DB::table('thanhviennhom')
                    ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                    ->join('sinhvien', 'thanhviennhom.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                    ->where('nhomsvda.dot_id', $dotId)
                    ->whereNotNull('sinhvien.lop_id')
                    ->distinct()
                    ->pluck('sinhvien.lop_id')
                    ->map(fn ($id) => (string) $id)
                    ->all();
            } else {
                $classIds = DB::table('dangkythuctap')
                    ->join('sinhvien', 'dangkythuctap.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                    ->where('dangkythuctap.dot_id', $dotId)
                    ->whereNotNull('sinhvien.lop_id')
                    ->distinct()
                    ->pluck('sinhvien.lop_id')
                    ->map(fn ($id) => (string) $id)
                    ->all();
            }
        }

        // Lấy danh sách sinh viên ngoài lớp (sinh viên tự do/rớt)
        $externalStudents = $dot->sinhViens()
            ->where(function ($q) use ($classIds) {
                if (! empty($classIds)) {
                    $q->whereNull('sinhvien.lop_id')
                      ->orWhereNotIn('sinhvien.lop_id', $classIds);
                }
            })
            ->get()
            ->map(function ($sv) {
                return [
                    'id' => (string) $sv->ma_so_sinh_vien,
                    'name' => $sv->ho_ten,
                    'email' => $sv->email,
                    'className' => $sv->lop ? $sv->lop->ten_lop : null,
                    'phone' => $sv->so_dien_thoai,
                    'role' => 'student',
                    'status' => $sv->dang_hoat_dong == 1 ? 'active' : 'inactive',
                    'gender' => $sv->gioi_tinh,
                    'dateOfBirth' => $sv->ngay_sinh,
                ];
            })->all();
        $externalStudentIds = collect($externalStudents)->pluck('id')->all();

        return [
            'id' => (string) $dot->dot_id,
            'name' => $dot->ten_dot,
            'type' => $type,
            'startDate' => $dot->ngay_bat_dau ? Carbon::parse($dot->ngay_bat_dau)->format('d/m/Y') : '',
            'endDate' => $dot->ngay_ket_thuc ? Carbon::parse($dot->ngay_ket_thuc)->format('d/m/Y') : '',
            'regDeadline' => $dot->han_dang_ky ? Carbon::parse($dot->han_dang_ky)->format('d/m/Y') : '',
            'regOpenDate' => $dot->ngay_bat_dau_dang_ky ? Carbon::parse($dot->ngay_bat_dau_dang_ky)->format('d/m/Y') : '',
            'reportDeadline' => $dot->han_nop_bao_cao ? Carbon::parse($dot->han_nop_bao_cao)->format('d/m/Y') : '',
            'gradingStartDate' => $dot->ngay_bat_dau_cham_diem ? Carbon::parse($dot->ngay_bat_dau_cham_diem)->format('d/m/Y') : '',
            'gradingEndDate' => $dot->ngay_ket_thuc_cham_diem ? Carbon::parse($dot->ngay_ket_thuc_cham_diem)->format('d/m/Y') : '',
            'reviewStartDate' => $dot->ngay_bat_dau_phan_bien ? Carbon::parse($dot->ngay_bat_dau_phan_bien)->format('d/m/Y') : '',
            'reviewEndDate' => $dot->ngay_ket_thuc_phan_bien ? Carbon::parse($dot->ngay_ket_thuc_phan_bien)->format('d/m/Y') : '',
            'defenseStartDate' => $dot->ngay_bat_dau_bao_ve ? Carbon::parse($dot->ngay_bat_dau_bao_ve)->format('d/m/Y') : '',
            'defenseEndDate' => $dot->ngay_ket_thuc_bao_ve ? Carbon::parse($dot->ngay_ket_thuc_bao_ve)->format('d/m/Y') : '',
            'semester' => $dot->hoc_ky,
            'schoolYear' => $dot->nam_hoc,
            'studentListFileName' => 'danh-sach-sinh-vien-'.strtolower($dot->loai_dot).'-'.$dot->dot_id.'.xlsx',
            'studentListUrl' => 'https://example.com/danh-sach-sinh-vien-'.$dot->dot_id.'.xlsx',
            'classIds' => $classIds,
            'externalStudents' => $externalStudents,
            'externalStudentIds' => $externalStudentIds,
            'numberDN' => $numberDN,
            'numberSV' => $numberSV,
            'numberTopics' => $numberTopics,
            'numberCouncils' => $numberCouncils,
            'status' => $this->mapBackendStatusToFrontend($dot->trang_thai),
        ];
    }

    /**
     * Map trạng thái Backend sang Frontend
     */
    private function mapBackendStatusToFrontend($status)
    {
        switch (strtoupper($status)) {
            case 'DANG_MO':
            case 'MO':
            case 'OPEN':
                return 'open';
            case 'DA_CONG_BO':
            case 'CHO_MO': // tên cũ, giữ lại để đọc tương thích dữ liệu/định dạng cũ nếu còn sót
            case 'CONG_BO':
            case 'PUBLISHED':
                return 'published';
            case 'CHAM_DIEM':
            case 'GRADING':
                return 'grading';
            case 'DA_DONG':
            case 'CLOSED':
            default:
                return 'closed';
        }
    }

    /**
     * Map trạng thái Frontend sang Backend
     */
    private function mapFrontendStatusToBackend($status)
    {
        switch (strtolower($status)) {
            case 'open':
                return 'DANG_MO';
            case 'published':
                return 'DA_CONG_BO';
            case 'grading':
                return 'CHAM_DIEM';
            case 'closed':
            default:
                return 'DA_DONG';
        }
    }

    /**
     * Parse ngày từ d/m/Y sang Y-m-d hoặc giữ nguyên nếu đúng dạng
     */
    private function parseDate($dateString)
    {
        if (empty($dateString)) {
            return null;
        }

        try {
            // Nếu có định dạng d/m/Y
            if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $dateString)) {
                return Carbon::createFromFormat('d/m/Y', $dateString)->format('Y-m-d');
            }

            return Carbon::parse($dateString)->format('Y-m-d');
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Thêm một sinh viên vào nhiều đợt đăng ký
     */
    public function addStudentToPeriods($studentCode, array $periodIds)
    {
        $student = SinhVien::where('ma_so_sinh_vien', $studentCode)
            ->orWhere('sinh_vien_id', $studentCode)
            ->first();
        if (! $student) {
            throw new \InvalidArgumentException('Không tìm thấy sinh viên trong hệ thống!');
        }

        $studentId = $student->sinh_vien_id;

        // Check if student is already in any of the selected periods
        $duplicatedPeriods = [];
        foreach ($periodIds as $periodId) {
            $dot = Dot::find($periodId);
            if ($dot && $dot->hasStudent($studentId)) {
                $duplicatedPeriods[] = $dot->ten_dot;
            }
        }

        if (! empty($duplicatedPeriods)) {
            throw new \InvalidArgumentException('Sinh viên ' . $student->ho_ten . ' (' . $student->ma_so_sinh_vien . ') đã tồn tại trong đợt: ' . implode(', ', $duplicatedPeriods) . '!');
        }

        foreach ($periodIds as $periodId) {
            $dot = Dot::find($periodId);
            if ($dot) {
                $dot->sinhViens()->syncWithoutDetaching([$studentId]);
            }
        }

        return true;
    }

    /**
     * Validate dates and school year of period.
     */
    private function validatePeriodDatesAndSchoolYear(array $data, $existingDot = null)
    {
        // 1. Kiểm tra Năm học (schoolYear)
        $schoolYear = $data['schoolYear'] ?? ($existingDot ? $existingDot->nam_hoc : null);
        if ($schoolYear) {
            $match = [];
            if (!preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $match)) {
                throw new \InvalidArgumentException('Định dạng năm học phải là YYYY-YYYY, VD: 2026-2027!');
            }
            $year1 = (int)$match[1];
            $year2 = (int)$match[2];
            if ($year2 <= $year1) {
                throw new \InvalidArgumentException('Năm học không hợp lệ: năm kết thúc phải lớn hơn năm bắt đầu!');
            }
        }

        // 2. Kiểm tra mốc thời gian
        $startDate = $this->parseDate($data['startDate'] ?? ($existingDot ? $existingDot->ngay_bat_dau : null));
        $endDate = $this->parseDate($data['endDate'] ?? ($existingDot ? $existingDot->ngay_ket_thuc : null));
        $regOpenDate = $this->parseDate($data['regOpenDate'] ?? ($existingDot ? $existingDot->ngay_bat_dau_dang_ky : null));
        $regDeadline = $this->parseDate($data['regDeadline'] ?? ($existingDot ? $existingDot->han_dang_ky : null));
        $reportDeadline = $this->parseDate($data['reportDeadline'] ?? ($existingDot ? $existingDot->han_nop_bao_cao : null));
        $reviewStartDate = $this->parseDate($data['reviewStartDate'] ?? ($existingDot ? $existingDot->ngay_bat_dau_phan_bien : null));
        $reviewEndDate = $this->parseDate($data['reviewEndDate'] ?? ($existingDot ? $existingDot->ngay_ket_thuc_phan_bien : null));
        $defenseStartDate = $this->parseDate($data['defenseStartDate'] ?? ($existingDot ? $existingDot->ngay_bat_dau_bao_ve : null));
        $defenseEndDate = $this->parseDate($data['defenseEndDate'] ?? ($existingDot ? $existingDot->ngay_ket_thuc_bao_ve : null));
        $gradingStartDate = $this->parseDate($data['gradingStartDate'] ?? ($existingDot ? $existingDot->ngay_bat_dau_cham_diem : null));
        $gradingEndDate = $this->parseDate($data['gradingEndDate'] ?? ($existingDot ? $existingDot->ngay_ket_thuc_cham_diem : null));

        if ($startDate && $endDate && Carbon::parse($endDate)->lte(Carbon::parse($startDate))) {
            throw new \InvalidArgumentException('Ngày kết thúc đợt học phải sau ngày bắt đầu!');
        }

        if ($startDate && $regOpenDate && Carbon::parse($regOpenDate)->lt(Carbon::parse($startDate))) {
            throw new \InvalidArgumentException('Mở đăng ký không được trước Bắt đầu!');
        }

        if ($regOpenDate && $regDeadline && Carbon::parse($regDeadline)->lte(Carbon::parse($regOpenDate))) {
            throw new \InvalidArgumentException('Hạn đăng ký phải sau ngày mở đăng ký!');
        }

        $loaiDot = strtoupper($data['type'] ?? ($existingDot ? $existingDot->loai_dot : 'tttn'));

        if ($regDeadline && $reportDeadline && Carbon::parse($reportDeadline)->lte(Carbon::parse($regDeadline))) {
            throw new \InvalidArgumentException('Hạn nộp báo cáo tiến độ phải sau hạn đăng ký!');
        }

        if ($loaiDot === 'DATN') {
            if ($reportDeadline && $reviewStartDate && Carbon::parse($reviewStartDate)->lte(Carbon::parse($reportDeadline))) {
                throw new \InvalidArgumentException('Ngày bắt đầu phản biện phải sau hạn nộp báo cáo!');
            }

            if ($reviewStartDate && $reviewEndDate && Carbon::parse($reviewEndDate)->lte(Carbon::parse($reviewStartDate))) {
                throw new \InvalidArgumentException('Ngày kết thúc phản biện phải sau ngày bắt đầu phản biện!');
            }

            if ($reviewEndDate && $defenseStartDate && Carbon::parse($defenseStartDate)->lte(Carbon::parse($reviewEndDate))) {
                throw new \InvalidArgumentException('Ngày bắt đầu bảo vệ phải sau ngày kết thúc phản biện!');
            }

            if ($defenseStartDate && $defenseEndDate && Carbon::parse($defenseEndDate)->lte(Carbon::parse($defenseStartDate))) {
                throw new \InvalidArgumentException('Ngày kết thúc bảo vệ phải sau ngày bắt đầu bảo vệ!');
            }

            if ($defenseStartDate && $gradingStartDate && Carbon::parse($gradingStartDate)->lt(Carbon::parse($defenseStartDate))) {
                throw new \InvalidArgumentException('Bắt đầu chấm điểm không được trước Bắt đầu bảo vệ!');
            }
        } else {
            // TTTN mode: Bắt đầu chấm điểm phải sau Hạn nộp báo cáo tiến độ
            if ($reportDeadline && $gradingStartDate && Carbon::parse($gradingStartDate)->lte(Carbon::parse($reportDeadline))) {
                throw new \InvalidArgumentException('Ngày bắt đầu chấm điểm phải sau hạn nộp báo cáo tiến độ!');
            }
        }

        if ($gradingStartDate && $gradingEndDate && Carbon::parse($gradingEndDate)->lte(Carbon::parse($gradingStartDate))) {
            throw new \InvalidArgumentException('Ngày kết thúc chấm điểm phải sau ngày bắt đầu chấm điểm!');
        }

        if ($loaiDot === 'DATN') {
            if ($defenseEndDate && $gradingEndDate && Carbon::parse($gradingEndDate)->lte(Carbon::parse($defenseEndDate))) {
                throw new \InvalidArgumentException('Ngày kết thúc chấm điểm phải sau ngày kết thúc bảo vệ!');
            }
        }

        if ($reportDeadline && $endDate && Carbon::parse($reportDeadline)->gte(Carbon::parse($endDate))) {
            throw new \InvalidArgumentException('Hạn nộp báo cáo tiến độ phải trước ngày kết thúc đợt học!');
        }

        if ($loaiDot === 'DATN') {
            if ($defenseEndDate && $endDate && Carbon::parse($defenseEndDate)->gte(Carbon::parse($endDate))) {
                throw new \InvalidArgumentException('Ngày kết thúc bảo vệ phải trước ngày kết thúc đợt học!');
            }
        }

        if ($gradingEndDate && $endDate && Carbon::parse($gradingEndDate)->gte(Carbon::parse($endDate))) {
            throw new \InvalidArgumentException('Ngày kết thúc chấm điểm phải trước ngày kết thúc đợt học!');
        }

        // 3. Kiểm tra ngày bắt đầu/ngày kết thúc khớp với năm học
        if ($schoolYear) {
            $match = [];
            if (preg_match('/^(\d{4})-(\d{4})$/', $schoolYear, $match)) {
                $startYear = (int)$match[1];
                $endYear = (int)$match[2];

                if ($startDate && Carbon::parse($startDate)->year < $startYear) {
                    throw new \InvalidArgumentException("Ngày bắt đầu phải từ năm học {$startYear} trở đi!");
                }

                if ($endDate && Carbon::parse($endDate)->year > $endYear) {
                    throw new \InvalidArgumentException("Ngày kết thúc không được vượt quá năm học {$endYear}!");
                }
            }
        }
    }
}
