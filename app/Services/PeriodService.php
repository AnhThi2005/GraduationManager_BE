<?php

namespace App\Services;

use App\Models\Dot;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PeriodService
{
    /**
     * Lấy danh sách các đợt đăng ký kèm phân trang và bộ lọc
     */
    public function getListPeriod(array $filters, $perPage = 10)
    {
        $query = Dot::query();

        // 1. Lọc theo keyword (tìm kiếm trong tên đợt, học kỳ, năm học)
        if (!empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->where('ten_dot', 'like', '%' . $keyword . '%')
                  ->orWhere('hoc_ky', 'like', '%' . $keyword . '%')
                  ->orWhere('nam_hoc', 'like', '%' . $keyword . '%');
            });
        }

        // 2. Lọc theo loại đợt (tttn / datn)
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('loai_dot', strtoupper($filters['type']));
        }

        // 3. Lọc theo trạng thái
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
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
            'hasMorePages' => $paginator->hasMorePages()
        ];
    }

    /**
     * Xem chi tiết đợt đăng ký bằng ID
     */
    public function getPeriodDetail($id)
    {
        $dot = Dot::find($id);
        if (!$dot) {
            return null;
        }

        return $this->transformPeriod($dot);
    }

    /**
     * Tạo đợt đăng ký mới
     */
    public function createPeriod(array $data)
    {
        $insertData = [
            'ten_dot' => $data['name'] ?? '',
            'loai_dot' => isset($data['type']) ? strtoupper($data['type']) : 'TTTN',
            'trang_thai' => isset($data['status']) ? $this->mapFrontendStatusToBackend($data['status']) : 'CHO_MO',
            'ngay_bat_dau' => $this->parseDate($data['startDate'] ?? null),
            'ngay_ket_thuc' => $this->parseDate($data['endDate'] ?? null),
            'han_dang_ky' => $this->parseDate($data['regDeadline'] ?? null),
            'hoc_ky' => $data['semester'] ?? 1,
            'nam_hoc' => $data['schoolYear'] ?? (date('Y') . '-' . (date('Y') + 1)),
            'giang_vien_id' => $data['teacherId'] ?? 1 // Mặc định gán giảng viên tạo
        ];

        // Tạo mốc thời gian phụ
        $insertData['ngay_bat_dau_dang_ky'] = Carbon::parse($insertData['ngay_bat_dau'])->subDays(15)->format('Y-m-d');
        $insertData['han_nop_bao_cao'] = Carbon::parse($insertData['ngay_ket_thuc'])->subDays(7)->format('Y-m-d');
        $insertData['ngay_bat_dau_cham_diem'] = $insertData['ngay_ket_thuc'];
        $insertData['ngay_ket_thuc_cham_diem'] = Carbon::parse($insertData['ngay_ket_thuc'])->addDays(15)->format('Y-m-d');

        $dot = Dot::create($insertData);

        if (!empty($data['classIds']) && is_array($data['classIds'])) {
            $dot->lops()->sync($data['classIds']);
        }

        return $this->transformPeriod($dot);
    }

    /**
     * Cập nhật đợt đăng ký
     */
    public function updatePeriod($id, array $data)
    {
        $dot = Dot::find($id);
        if (!$dot) {
            return null;
        }

        $updateData = [];
        if (isset($data['name'])) $updateData['ten_dot'] = $data['name'];
        if (isset($data['type'])) $updateData['loai_dot'] = strtoupper($data['type']);
        if (isset($data['status'])) $updateData['trang_thai'] = $this->mapFrontendStatusToBackend($data['status']);
        if (isset($data['startDate'])) $updateData['ngay_bat_dau'] = $this->parseDate($data['startDate']);
        if (isset($data['endDate'])) $updateData['ngay_ket_thuc'] = $this->parseDate($data['endDate']);
        if (isset($data['regDeadline'])) $updateData['han_dang_ky'] = $this->parseDate($data['regDeadline']);
        if (isset($data['semester'])) $updateData['hoc_ky'] = $data['semester'];
        if (isset($data['schoolYear'])) $updateData['nam_hoc'] = $data['schoolYear'];

        $dot->update($updateData);

        if (isset($data['classIds']) && is_array($data['classIds'])) {
            $dot->lops()->sync($data['classIds']);
        }

        return $this->transformPeriod($dot->fresh());
    }

    /**
     * Xóa đợt đăng ký
     */
    public function deletePeriod($id)
    {
        $dot = Dot::find($id);
        if (!$dot) {
            return false;
        }

        $dot->delete();
        return true;
    }

    // ==========================================================
    // HELPER FUNCTIONS
    // ==========================================================

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
        $classIds = $dot->lops()->pluck('lop.lop_id')->map(fn($id) => (string)$id)->all();
        if (empty($classIds)) {
            if ($type === 'datn') {
                $classIds = DB::table('thanhviennhom')
                    ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                    ->join('sinhvien', 'thanhviennhom.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                    ->where('nhomsvda.dot_id', $dotId)
                    ->whereNotNull('sinhvien.lop_id')
                    ->distinct()
                    ->pluck('sinhvien.lop_id')
                    ->map(fn($id) => (string)$id)
                    ->all();
            } else {
                $classIds = DB::table('dangkythuctap')
                    ->join('sinhvien', 'dangkythuctap.sinh_vien_id', '=', 'sinhvien.sinh_vien_id')
                    ->where('dangkythuctap.dot_id', $dotId)
                    ->whereNotNull('sinhvien.lop_id')
                    ->distinct()
                    ->pluck('sinhvien.lop_id')
                    ->map(fn($id) => (string)$id)
                    ->all();
            }
        }

        return [
            'id' => (string)$dot->dot_id,
            'name' => $dot->ten_dot,
            'type' => $type,
            'startDate' => $dot->ngay_bat_dau ? Carbon::parse($dot->ngay_bat_dau)->format('d/m/Y') : '',
            'endDate' => $dot->ngay_ket_thuc ? Carbon::parse($dot->ngay_ket_thuc)->format('d/m/Y') : '',
            'regDeadline' => $dot->han_dang_ky ? Carbon::parse($dot->han_dang_ky)->format('d/m/Y') : '',
            'studentListFileName' => 'danh-sach-sinh-vien-' . strtolower($dot->loai_dot) . '-' . $dot->dot_id . '.xlsx',
            'studentListUrl' => 'https://example.com/danh-sach-sinh-vien-' . $dot->dot_id . '.xlsx',
            'classIds' => $classIds,
            'numberDN' => $numberDN,
            'numberSV' => $numberSV,
            'numberTopics' => $numberTopics,
            'numberCouncils' => $numberCouncils,
            'status' => $this->mapBackendStatusToFrontend($dot->trang_thai)
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
            case 'CHO_MO':
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
                return 'CHO_MO';
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
}
