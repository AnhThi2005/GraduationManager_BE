<?php

namespace App\Services;

use App\Models\DeTai;
use App\Models\GiangVien;
use App\Models\Dot;
use Illuminate\Support\Facades\DB;

class DeTaiService
{
    /**
     * Lấy danh sách đề tài (Có phân trang & bộ lọc)
     */
    public function getListTopic(array $filters, $perPage = 10)
    {
        $query = DeTai::with('giangVien');

        // Lọc theo đợt học
        if (!empty($filters['periodId'])) {
            $query->where('dot_id', $filters['periodId']);
        }

        // Lọc theo trạng thái duyệt
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('trang_thai', $this->mapFrontendStatusToBackend($filters['status']));
        }

        // Lọc theo từ khóa tìm kiếm (tên đề tài, họ tên giảng viên)
        if (!empty($filters['keyword'])) {
            $keyword = trim($filters['keyword']);
            $query->where(function ($q) use ($keyword) {
                $q->where('ten_de_tai', 'like', '%' . $keyword . '%')
                  ->orWhereHas('giangVien', function ($sub) use ($keyword) {
                      $sub->where('ho_ten', 'like', '%' . $keyword . '%');
                  });
            });
        }

        $query->orderBy('de_tai_id', 'desc');

        $paginator = $query->paginate($perPage);

        $rows = collect($paginator->items())->map(function ($deTai) {
            return $this->transformTopic($deTai);
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
     * Xem chi tiết đề tài
     */
    public function getTopicDetail($id)
    {
        $deTai = DeTai::with('giangVien')->find($id);
        if (!$deTai) {
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
        if (!$dotId) {
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
            'huong_de_tai' => $this->mapFrontendDirectionToBackend($data['direction'] ?? ''),
            'trang_thai' => $status,
            'ly_do_tu_choi' => $rejectReason
        ]);

        return $this->getTopicDetail($deTai->de_tai_id);
    }

    /**
     * Cập nhật thông tin đề tài
     */
    public function updateTopic($id, array $data)
    {
        $deTai = DeTai::find($id);
        if (!$deTai) {
            return null;
        }

        $updateData = [];
        if (isset($data['name'])) $updateData['ten_de_tai'] = $data['name'];
        if (isset($data['description'])) $updateData['mo_ta'] = $data['description'];
        if (isset($data['fileUrl'])) $updateData['file_mo_ta'] = $data['fileUrl'];
        if (isset($data['direction'])) $updateData['huong_de_tai'] = $this->mapFrontendDirectionToBackend($data['direction']);
        
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

        return $this->getTopicDetail($id);
    }

    /**
     * Xóa đề tài
     */
    public function deleteTopic($id)
    {
        $deTai = DeTai::find($id);
        if (!$deTai) {
            return false;
        }

        $deTai->delete();
        return true;
    }

    // ==========================================================
    // HELPER METHODS
    // ==========================================================

    /**
     * Map DeTai model sang cấu trúc JSON Frontend mong đợi
     */
    private function transformTopic($deTai)
    {
        // Tính số lượng SV hiện tại đã đăng ký đề tài này
        $occupiedSlots = DB::table('thanhviennhom')
            ->join('nhomsvda', 'thanhviennhom.nhom_id', '=', 'nhomsvda.nhom_id')
            ->where('nhomsvda.de_tai_id', $deTai->de_tai_id)
            ->count();

        $maxSlots = $deTai->so_luong_sv_toi_da ?? 4;
        $slotsStr = $occupiedSlots . '/' . $maxSlots;

        // Định dạng tên giảng viên kèm học vị
        $teacherName = 'Chưa phân công';
        if ($deTai->giangVien) {
            $teacherName = ($deTai->giangVien->hoc_vi ? $deTai->giangVien->hoc_vi . '. ' : '') . $deTai->giangVien->ho_ten;
        }

        return [
            'id' => (string)$deTai->de_tai_id,
            'code' => 'DA' . str_pad($deTai->de_tai_id, 3, '0', STR_PAD_LEFT),
            'name' => $deTai->ten_de_tai,
            'teacher' => $teacherName,
            'slots' => $slotsStr,
            'rejectReason' => $deTai->ly_do_tu_choi ?? '',
            'status' => $this->mapBackendStatusToFrontend($deTai->trang_thai)
        ];
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
            return (int)$matches[1];
        }

        if (is_numeric($slotsVal)) {
            return (int)$slotsVal;
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

        $giangVien = GiangVien::where('ho_ten', 'like', '%' . trim($cleanName) . '%')->first();

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

    private function mapFrontendDirectionToBackend($direction)
    {
        $directionUpper = mb_strtoupper($direction);
        if (str_contains($directionUpper, 'MẠNG') || str_contains($directionUpper, 'MANG') || str_contains($directionUpper, 'NETWORK')) {
            return 'MANG_MAY_TINH';
        }
        return 'PHAN_MEM';
    }
}
