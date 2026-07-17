<?php

namespace App\Services;

use App\Models\DeTai;
use App\Models\Dot;
use App\Models\GiangVien;
use App\Models\Nhom;
use Illuminate\Support\Facades\DB;

class DeTaiService
{
    /**
     * Lấy danh sách đề tài (Có phân trang & bộ lọc)
     */
    public function getListTopic(array $filters, $perPage = 10)
    {
        $query = DeTai::with(['giangVien', 'dot', 'huongDeTais']);

        // Lọc theo giảng viên (theo ID, dùng nội bộ nếu có)
        if (! empty($filters['teacherId'])) {
            $query->where('giang_vien_id', $filters['teacherId']);
        }

        // Lọc theo tên giảng viên (từ bộ lọc dropdown trên trang Quản lý đề tài)
        if (! empty($filters['teacher']) && $filters['teacher'] !== 'all') {
            $teacherName = $filters['teacher'];
            $query->whereHas('giangVien', function ($sub) use ($teacherName) {
                $sub->where('ho_ten', $teacherName);
            });
        }

        // Lọc theo đợt học
        if (! empty($filters['periodId'])) {
            $query->where('dot_id', $filters['periodId']);
        }

        // Lọc theo hướng đề tài
        if (! empty($filters['direction']) && $filters['direction'] !== 'all') {
            $dir = $filters['direction'];
            $query->whereHas('huongDeTais', function ($sub) use ($dir) {
                if (is_numeric($dir)) {
                    $sub->where('huongdetai.huong_de_tai_id', (int)$dir);
                } elseif ($dir === 'MANG_MAY_TINH') {
                    $sub->where('huongdetai.ten_huong_de_tai', 'like', '%mạng%')
                        ->orWhere('huongdetai.ten_huong_de_tai', 'like', '%network%');
                } elseif ($dir === 'PHAN_MEM') {
                    $sub->where('huongdetai.ten_huong_de_tai', 'not like', '%mạng%')
                        ->where('huongdetai.ten_huong_de_tai', 'not like', '%network%');
                } else {
                    $sub->where('huongdetai.ten_huong_de_tai', 'like', '%' . $dir . '%');
                }
            });
        }

        // Lọc theo trạng thái duyệt
        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('trang_thai', $this->mapFrontendStatusToBackend($filters['status']));
        }

        // Lọc theo từ khóa tìm kiếm (tên đề tài, họ tên giảng viên)
        if (! empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->where('ten_de_tai', 'like', '%'.$keyword.'%')
                    ->orWhereHas('giangVien', function ($sub) use ($keyword) {
                        $sub->where('ho_ten', 'like', '%'.$keyword.'%');
                    });
            });
        }

        $query->orderBy('de_tai_id', 'desc');

        $paginator = $query->paginate($perPage);

        // Gộp COUNT slot đã đăng ký/đã duyệt của cả trang thành 2 query duy nhất
        // (group theo de_tai_id) thay vì 2 query riêng cho từng đề tài, tránh N+1.
        $deTaiIds = collect($paginator->items())->pluck('de_tai_id');
        [$occupiedByTopic, $approvedByTopic] = $this->getSlotCountsForTopics($deTaiIds);

        $rows = collect($paginator->items())->map(function ($deTai) use ($occupiedByTopic, $approvedByTopic) {
            return $this->transformTopic(
                $deTai,
                $occupiedByTopic[$deTai->de_tai_id] ?? 0,
                $approvedByTopic[$deTai->de_tai_id] ?? 0
            );
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
     * Xem chi tiết đề tài
     */
    public function getTopicDetail($id)
    {
        $deTai = DeTai::with(['giangVien', 'dot', 'huongDeTais'])->find($id);
        if (! $deTai) {
            return null;
        }

        return $this->transformTopic($deTai);
    }

    /**
     * Đề xuất/Tạo mới đề tài
     */
    public function createTopic(array $data, $periodId = null)
    {
        $dotId = $periodId ?? $data['periodId'] ?? null;
        if (! $dotId) {
            // Lấy đợt DATN mới nhất làm mặc định
            $activePeriod = Dot::where('loai_dot', 'DATN')->orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        $giangVienId = $this->lookupTeacherId($data['teacher'] ?? '');
        $maxSlots = $this->parseMaxSlots($data['slots'] ?? '');
        $status = isset($data['status']) ? $this->mapFrontendStatusToBackend($data['status']) : 'CHO_DUYET';
        $rejectReason = ($status === 'TU_CHOI') ? ($data['rejectReason'] ?? null) : null;

        $deTai = DeTai::create([
            'dot_id' => $dotId,
            'giang_vien_id' => $giangVienId,
            'ten_de_tai' => $data['name'] ?? '',
            'mo_ta' => $data['description'] ?? '',
            'file_mo_ta' => $data['fileUrl'] ?? null,
            'so_luong_sv_toi_da' => $maxSlots,
            'trang_thai' => $status,
            'ly_do_tu_choi' => $rejectReason,
        ]);

        $this->syncDirections($deTai, $data['direction'] ?? $data['directionIds'] ?? null);

        return $this->getTopicDetail($deTai->de_tai_id);
    }

    /**
     * Cập nhật thông tin đề tài
     */
    public function updateTopic($id, array $data)
    {
        $deTai = DeTai::find($id);
        if (! $deTai) {
            return null;
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['ten_de_tai'] = $data['name'];
        }
        if (isset($data['description'])) {
            $updateData['mo_ta'] = $data['description'];
        }
        if (isset($data['fileUrl'])) {
            $updateData['file_mo_ta'] = $data['fileUrl'];
        }

        $newStatus = null;
        if (isset($data['status'])) {
            $newStatus = $this->mapFrontendStatusToBackend($data['status']);
            $updateData['trang_thai'] = $newStatus;

            // Clear rejection reason if state changes to approved or pending
            if ($newStatus !== 'TU_CHOI') {
                $updateData['ly_do_tu_choi'] = null;
            }
        }

        if (isset($data['rejectReason'])) {
            $currentStatus = $newStatus ?? $deTai->trang_thai;
            if ($currentStatus === 'TU_CHOI') {
                $updateData['ly_do_tu_choi'] = $data['rejectReason'];
            }
        }

        if (isset($data['teacher'])) {
            $updateData['giang_vien_id'] = $this->lookupTeacherId($data['teacher']);
        }

        if (isset($data['slots'])) {
            $updateData['so_luong_sv_toi_da'] = $this->parseMaxSlots($data['slots']);
        }

        $deTai->update($updateData);

        if (isset($data['direction']) || isset($data['directionIds'])) {
            $this->syncDirections($deTai, $data['direction'] ?? $data['directionIds']);
        }

        return $this->getTopicDetail($id);
    }

    /**
     * Xóa đề tài
     */
    public function deleteTopic($id)
    {
        $deTai = DeTai::find($id);
        if (! $deTai) {
            return false;
        }

        if (Nhom::where('de_tai_id', $id)->exists()) {
            throw new \InvalidArgumentException(
                'Không thể xóa đề tài này vì đã có nhóm sinh viên đăng ký. Vui lòng xử lý nhóm liên quan trước.'
            );
        }

        $deTai->delete();

        return true;
    }

    // ==========================================================
    // HELPER METHODS
    // ==========================================================

    /**
     * Tính số slot đã đăng ký / đã duyệt cho một tập đề tài trong 1-2 query gộp
     * (group theo de_tai_id), dùng cho danh sách để tránh N+1 (2 query/dòng).
     *
     * @return array{0: array<int,int>, 1: array<int,int>} [occupiedByTopic, approvedByTopic]
     */
    private function getSlotCountsForTopics($deTaiIds)
    {
        $deTaiIds = collect($deTaiIds)->filter()->values();
        if ($deTaiIds->isEmpty()) {
            return [[], []];
        }

        $occupiedByTopic = DB::table('thanhviennhom')
            ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
            ->whereIn('nhomsvda.de_tai_id', $deTaiIds)
            ->selectRaw('nhomsvda.de_tai_id as de_tai_id, count(*) as total')
            ->groupBy('nhomsvda.de_tai_id')
            ->pluck('total', 'de_tai_id');

        $approvedByTopic = DB::table('thanhviennhom')
            ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
            ->whereIn('nhomsvda.de_tai_id', $deTaiIds)
            ->where('nhomsvda.trang_thai_duyet', 'DA_DUYET')
            ->selectRaw('nhomsvda.de_tai_id as de_tai_id, count(*) as total')
            ->groupBy('nhomsvda.de_tai_id')
            ->pluck('total', 'de_tai_id');

        return [$occupiedByTopic->all(), $approvedByTopic->all()];
    }

    /**
     * Map DeTai model sang cấu trúc JSON Frontend mong đợi.
     *
     * $occupiedSlots/$approvedSlots có thể truyền sẵn (đã gộp query cho cả danh sách,
     * xem getListTopic) — nếu không truyền (null), tự tính riêng cho 1 đề tài (dùng ở
     * getTopicDetail, chỉ 1 dòng nên không phát sinh N+1).
     */
    private function transformTopic($deTai, $occupiedSlots = null, $approvedSlots = null)
    {
        // Tính số lượng SV hiện tại đã đăng ký đề tài này
        if ($occupiedSlots === null) {
            $occupiedSlots = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.de_tai_id', $deTai->de_tai_id)
                ->count();
        }

        $maxSlots = $deTai->so_luong_sv_toi_da ?? 4;
        $slotsStr = $occupiedSlots.'/'.$maxSlots;

        // Tính số lượng SV đã được duyệt vào đề tài này
        if ($approvedSlots === null) {
            $approvedSlots = DB::table('thanhviennhom')
                ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
                ->where('nhomsvda.de_tai_id', $deTai->de_tai_id)
                ->where('nhomsvda.trang_thai_duyet', 'DA_DUYET')
                ->count();
        }

        if ($approvedSlots > $maxSlots) {
            $approvedSlots = $maxSlots;
        }

        $approvedStudentsVal = ($deTai->trang_thai !== 'DA_DUYET' || $approvedSlots === 0) ? 'chưa có' : (string) $approvedSlots;

        // Định dạng tên giảng viên kèm học vị
        $teacherName = 'Chưa phân công';
        if ($deTai->giangVien) {
            $teacherName = $deTai->giangVien->ho_ten;
        }

        return [
            'id' => (string) $deTai->de_tai_id,
            'code' => $this->buildTopicCode($deTai),
            'name' => $deTai->ten_de_tai,
            'teacher' => $teacherName,
            'slots' => $slotsStr,
            'approved_students' => $approvedStudentsVal,
            'rejectReason' => $deTai->ly_do_tu_choi ?? '',
            'status' => $this->mapBackendStatusToFrontend($deTai->trang_thai),
            'description' => $deTai->mo_ta ?? '',
            'direction' => $deTai->huongDeTais->pluck('ten_huong_de_tai')->implode(', ') ?: 'Chưa xác định',
            'direction_ids' => $deTai->huongDeTais->pluck('huong_de_tai_id')->map(fn($id) => (string)$id)->all(),
            'fileUrl' => $deTai->file_mo_ta ?? '',
            'period' => $deTai->dot ? $deTai->dot->ten_dot : '',
        ];
    }

    /**
     * Sinh mã đề tài dạng DT{YY}{K}-{XX}-{Y}
     * - YY: 2 số cuối của năm bắt đầu (nam_hoc, VD "2025-2026" -> "25")
     * - K: học kỳ (chỉ hỗ trợ '1' hoặc '2' theo quy ước hiện tại; đợt hè 'HE' không nằm
     *   trong quy ước gốc nên tạm giữ nguyên 'HE' để không đoán sai, cần xác nhận thêm)
     * - XX: số thứ tự (2 chữ số) của đề tài trong đợt, tính theo de_tai_id tăng dần
     * - Y: số lượng sinh viên tối đa của đề tài
     * Nếu đề tài chưa gắn với đợt hoặc năm học không đúng định dạng "YYYY-YYYY",
     * không đủ dữ liệu để build mã theo quy ước -> dùng mã dự phòng DA{id}.
     */
    private function buildTopicCode($deTai)
    {
        $dot = $deTai->dot;
        if (! $dot || ! $dot->nam_hoc || ! preg_match('/^(\d{4})-\d{4}$/', $dot->nam_hoc, $m)) {
            return 'DA'.str_pad($deTai->de_tai_id, 3, '0', STR_PAD_LEFT);
        }

        $yearPart = substr($m[1], 2, 2);
        $semesterPart = $dot->hoc_ky ?: '1';

        $maxSlots = $deTai->so_luong_sv_toi_da ?? 4;

        return 'DT'.$yearPart.$semesterPart.'-'.str_pad($deTai->de_tai_id, 2, '0', STR_PAD_LEFT).'-'.$maxSlots;
    }

    /**
     * Phân tích số lượng sinh viên tối đa từ chuỗi slots (ví dụ: "0/3" hoặc "3" -> 3)
     */
    private function parseMaxSlots($slotsVal)
    {
        $slotsVal = trim($slotsVal);
        if (empty($slotsVal)) {
            return 4;
        }

        if (preg_match('/\/(\d+)/', $slotsVal, $matches)) {
            return (int) $matches[1];
        }

        if (is_numeric($slotsVal)) {
            return (int) $slotsVal;
        }

        return 4;
    }

    /**
     * Tìm kiếm GiangVienID theo tên
     */
    private function lookupTeacherId($teacherName)
    {
        $teacherName = trim($teacherName);
        if (empty($teacherName)) {
            return 1;
        }

        // Bỏ học hàm học vị ở đầu tên (VD: TS. Nguyễn Văn X -> Nguyễn Văn X)
        $cleanName = preg_replace('/^(TS\.|ThS\.|PGS\.|GS\.|Dr\.)\s+/iu', '', $teacherName);

        $giangVien = GiangVien::where('ho_ten', 'like', '%'.trim($cleanName).'%')->first();

        return $giangVien ? $giangVien->giang_vien_id : 1;
    }

    private function mapBackendStatusToFrontend($status)
    {
        switch (strtoupper($status)) {
            case 'DA_DUYET':
                return 'approved';
            case 'TU_CHOI':
                return 'rejected';
            case 'CHO_DUYET':
            default:
                return 'pending';
        }
    }

    private function mapFrontendStatusToBackend($status)
    {
        switch (strtolower($status)) {
            case 'approved':
                return 'DA_DUYET';
            case 'rejected':
                return 'TU_CHOI';
            case 'pending':
            default:
                return 'CHO_DUYET';
        }
    }

    public function syncDirections($deTai, $directionInput)
    {
        if (empty($directionInput)) {
            $deTai->huongDeTais()->detach();
            return;
        }

        $ids = [];

        // Nếu input là chuỗi, có thể là "1, 2" hoặc "Lập trình Web, Lập trình di động"
        if (is_string($directionInput)) {
            if (preg_match('/^[\d,\s]+$/', $directionInput)) {
                $directionInput = array_map('intval', explode(',', $directionInput));
            } else {
                $directionInput = array_map('trim', explode(',', $directionInput));
            }
        }

        if (is_array($directionInput)) {
            foreach ($directionInput as $item) {
                if (is_numeric($item)) {
                    $ids[] = (int)$item;
                } elseif (is_string($item)) {
                    $item = trim($item);
                    if (empty($item)) continue;

                    // Tìm hoặc tạo hướng đề tài
                    $huong = \App\Models\HuongDeTai::where('ten_huong_de_tai', 'like', $item)
                        ->orWhere('ten_huong_de_tai', 'like', '%' . $item . '%')
                        ->first();
                    if ($huong) {
                        $ids[] = $huong->huong_de_tai_id;
                    } else {
                        // Tạo mới nếu chưa tồn tại
                        $newHuong = \App\Models\HuongDeTai::create([
                            'ten_huong_de_tai' => $item,
                            'trang_thai_hd' => 1
                        ]);
                        $ids[] = $newHuong->huong_de_tai_id;
                    }
                }
            }
        }

        $deTai->huongDeTais()->sync(array_unique($ids));
    }


}
