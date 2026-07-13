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
        $loaiDot = isset($data['type']) ? strtoupper($data['type']) : 'TTTN';
        $trangThaiMoi = isset($data['status']) ? $this->mapFrontendStatusToBackend($data['status']) : 'DA_CONG_BO';
        $this->assertKhongTrungDotDangHoatDong($loaiDot, $trangThaiMoi);

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
            ?? Carbon::parse($insertData['ngay_bat_dau'])->subDays(15)->format('Y-m-d');
        $insertData['han_nop_bao_cao'] = $this->parseDate($data['reportDeadline'] ?? null)
            ?? Carbon::parse($insertData['ngay_ket_thuc'])->subDays(7)->format('Y-m-d');
        $insertData['ngay_bat_dau_cham_diem'] = $this->parseDate($data['gradingStartDate'] ?? null)
            ?? $insertData['ngay_ket_thuc'];
        $insertData['ngay_ket_thuc_cham_diem'] = $this->parseDate($data['gradingEndDate'] ?? null)
            ?? Carbon::parse($insertData['ngay_ket_thuc'])->addDays(15)->format('Y-m-d');

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

        if ($dot->daKhoaHoanToan()) {
            $disallowedKeys = array_diff(array_keys($data), ['status', 'type']);
            if (! empty($disallowedKeys)) {
                throw new \InvalidArgumentException(
                    "Đợt \"{$dot->ten_dot}\" đã đóng, không thể chỉnh sửa thông tin cấu hình của đợt này nữa."
                );
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
        if ($trangThaiMoi === 'DA_DONG') {
            return;
        }

        $query = Dot::where('loai_dot', $loaiDot)->where('trang_thai', '!=', 'DA_DONG');
        if ($excludeDotId) {
            $query->where('dot_id', '!=', $excludeDotId);
        }

        $dangHoatDong = $query->first();
        if ($dangHoatDong) {
            $tenLoai = $loaiDot === 'DATN' ? 'ĐATN' : 'TTTN';
            throw new \InvalidArgumentException(
                "Đợt \"{$dangHoatDong->ten_dot}\" ({$tenLoai}) đang hoạt động (chưa đóng). Vui lòng đóng đợt đó trước khi mở/kích hoạt đợt này — mỗi loại đợt chỉ được có 1 đợt hoạt động cùng lúc."
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
        $externalStudents = $dot->sinhViens->map(function ($sv) {
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
            return false;
        }

        $studentId = $student->sinh_vien_id;

        foreach ($periodIds as $periodId) {
            $dot = Dot::find($periodId);
            if ($dot) {
                $dot->sinhViens()->syncWithoutDetaching([$studentId]);
            }
        }

        return true;
    }
}
