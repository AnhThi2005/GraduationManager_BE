<?php

namespace App\Services;

use App\Models\CongTy;
use App\Models\DangKyThucTap;
use App\Models\SinhVien;
use App\Models\Dot;
use App\Models\PhanCongHdtt;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    // ==========================================================
    // 1. QUẢN LÝ DOANH NGHIỆP (COMPANIES CRUD)
    // ==========================================================

    /**
     * Lấy danh sách doanh nghiệp
     */
    public function getListCompany()
    {
        $companies = CongTy::all();

        $rows = $companies->map(function ($company) {
            return $this->transformCompany($company);
        })->all();

        return [
            'rows' => $rows,
            'total' => count($rows)
        ];
    }

    /**
     * Xem chi tiết doanh nghiệp
     */
    public function getCompanyDetail($id)
    {
        $company = CongTy::find($id);
        if (!$company) {
            return null;
        }

        return $this->transformCompany($company);
    }

    /**
     * Tạo doanh nghiệp mới
     */
    public function createCompany(array $data)
    {
        $status = isset($data['status']) ? $this->mapFrontendStatusToBackend($data['status']) : 'HOAT_DONG';

        $company = CongTy::create([
            'ten_cong_ty' => $data['name'] ?? '',
            'dia_chi' => $data['companyAddress'] ?? $data['address'] ?? '',
            'ma_so_thue' => $data['taxId'] ?? '',
            'nguoi_lien_he' => $data['contact'] ?? '',
            'email_lien_he' => $data['email'] ?? '',
            'so_dien_thoai_lh' => $data['phone'] ?? '',
            'trang_thai' => $status
        ]);

        $companyId = $company->cong_ty_id;

        // Lưu lĩnh vực hoạt động nếu có
        if (!empty($data['field'])) {
            $fields = array_map('trim', explode(',', $data['field']));
            foreach ($fields as $f) {
                DB::table('congtylinhvuc')->insert([
                    'cong_ty_id' => $companyId,
                    'ten_linh_vuc' => $f
                ]);
            }
        }

        return $this->getCompanyDetail($companyId);
    }

    /**
     * Cập nhật doanh nghiệp
     */
    public function updateCompany($id, array $data)
    {
        $company = CongTy::find($id);
        if (!$company) {
            return null;
        }

        $updateData = [];
        if (isset($data['name'])) $updateData['ten_cong_ty'] = $data['name'];
        if (isset($data['address'])) $updateData['dia_chi'] = $data['address'];
        if (isset($data['companyAddress'])) $updateData['dia_chi'] = $data['companyAddress'];
        if (isset($data['taxId'])) $updateData['ma_so_thue'] = $data['taxId'];
        if (isset($data['contact'])) $updateData['nguoi_lien_he'] = $data['contact'];
        if (isset($data['email'])) $updateData['email_lien_he'] = $data['email'];
        if (isset($data['phone'])) $updateData['so_dien_thoai_lh'] = $data['phone'];
        if (isset($data['status'])) $updateData['trang_thai'] = $this->mapFrontendStatusToBackend($data['status']);

        $company->update($updateData);

        // Cập nhật lĩnh vực hoạt động
        if (isset($data['field'])) {
            DB::table('congtylinhvuc')->where('cong_ty_id', $id)->delete();
            if (!empty($data['field'])) {
                $fields = array_map('trim', explode(',', $data['field']));
                foreach ($fields as $f) {
                    DB::table('congtylinhvuc')->insert([
                        'cong_ty_id' => $id,
                        'ten_linh_vuc' => $f
                    ]);
                }
            }
        }

        return $this->getCompanyDetail($id);
    }

    /**
     * Xóa doanh nghiệp
     */
    public function deleteCompany($id)
    {
        $company = CongTy::find($id);
        if (!$company) {
            return false;
        }

        // Gỡ liên kết trong dangkythuctap
        DangKyThucTap::where('cong_ty_id', $id)->update(['cong_ty_id' => null]);
        
        // Xóa lĩnh vực hoạt động
        DB::table('congtylinhvuc')->where('cong_ty_id', $id)->delete();

        $company->delete();
        return true;
    }

    // ==========================================================
    // 2. KHAI BÁO THỰC TẬP (INTERNSHIP CONFIRMATIONS)
    // ==========================================================

    /**
     * Danh sách yêu cầu xác nhận thực tập tự tìm
     */
    public function getListConfirmationRequest(array $filters)
    {
        $query = DangKyThucTap::query()->with(['sinhVien.lop', 'congTy']);

        // Lọc theo đợt học
        if (!empty($filters['periodId'])) {
            $query->where('dot_id', $filters['periodId']);
        }

        $registrations = $query->get();

        $rows = $registrations->map(function ($reg) {
            return $this->transformConfirmation($reg);
        })->all();

        return [
            'rows' => $rows,
            'total' => count($rows)
        ];
    }

    /**
     * Chi tiết yêu cầu xác nhận thực tập
     */
    public function getConfirmationRequestDetail($id)
    {
        $reg = DangKyThucTap::with(['sinhVien.lop', 'congTy'])->find($id);
        if (!$reg) {
            return null;
        }

        return $this->transformConfirmation($reg);
    }

    /**
     * Gửi yêu cầu tự khai báo nơi thực tập
     */
    public function createConfirmationRequest(array $data, $periodId = null)
    {
        $studentId = $data['studentId'] ?? '';
        $student = SinhVien::where('ma_so_sinh_vien', $studentId)->first();
        if (!$student) {
            return null;
        }

        // Tìm hoặc tạo mới công ty
        $taxId = $data['taxId'] ?? '';
        $company = CongTy::where('ma_so_thue', $taxId)->first();
        if (!$company) {
            $company = CongTy::create([
                'ten_cong_ty' => $data['companyName'] ?? '',
                'dia_chi' => $data['companyAddress'] ?? '',
                'ma_so_thue' => $taxId,
                'nguoi_lien_he' => $data['mentor'] ?? '',
                'email_lien_he' => $data['email'] ?? '',
                'so_dien_thoai_lh' => $data['phone'] ?? '',
                'trang_thai' => 'NGUNG_HOAT_DONG' // Chưa duyệt làm đối tác chính thức
            ]);
        }

        $dotId = $periodId ?? $data['periodId'] ?? null;
        if (!$dotId) {
            // Lấy đợt TTTN đang mở làm mặc định
            $activePeriod = Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        $reg = DangKyThucTap::create([
            'sinh_vien_id' => $student->sinh_vien_id,
            'dot_id' => $dotId,
            'cong_ty_id' => $company->cong_ty_id,
            'nguoi_huong_dan' => $data['mentor'] ?? '',
            'sdt_huong_dan' => $data['phone'] ?? '',
            'vi_tri_thuc_tap' => $data['internshipLocation'] ?? $data['vi_tri_thuc_tap'] ?? '',
            'thoi_gian_thuc_tap' => $data['thoi_gian_thuc_tap'] ?? '8 tuần',
            'dia_chi_thuc_tap' => $data['companyAddress'] ?? '',
            'trang_thai' => 'CHO_DUYET'
        ]);

        return $this->getConfirmationRequestDetail($reg->dang_ky_id);
    }

    /**
     * Phê duyệt hoặc từ chối yêu cầu tự khai báo
     */
    public function updateConfirmationRequest($id, array $data)
    {
        $reg = DangKyThucTap::find($id);
        if (!$reg) {
            return null;
        }

        $updateData = [];
        if (isset($data['status'])) {
            $updateData['trang_thai'] = $this->mapFrontendConfirmStatusToBackend($data['status']);
        }
        if (isset($data['mentor'])) $updateData['nguoi_huong_dan'] = $data['mentor'];
        if (isset($data['phone'])) $updateData['sdt_huong_dan'] = $data['phone'];
        if (isset($data['internshipLocation'])) $updateData['vi_tri_thuc_tap'] = $data['internshipLocation'];
        if (isset($data['companyAddress'])) $updateData['dia_chi_thuc_tap'] = $data['companyAddress'];

        $reg->update($updateData);

        return $this->getConfirmationRequestDetail($id);
    }

    /**
     * Xóa yêu cầu tự khai báo
     */
    public function deleteConfirmationRequest($id)
    {
        $reg = DangKyThucTap::find($id);
        if (!$reg) {
            return false;
        }

        $reg->delete();
        return true;
    }

    // ==========================================================
    // 3. SINH VIÊN CHƯA CÓ NƠI THỰC TẬP (NO COMPANY STUDENTS)
    // ==========================================================

    /**
     * Danh sách sinh viên chưa có nơi thực tập
     */
    public function getListNoCompanyStudent(array $filters)
    {
        $periodId = $filters['periodId'] ?? null;
        if (!$periodId) {
            // Lấy đợt TTTN mới nhất
            $activePeriod = Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
            $periodId = $activePeriod ? $activePeriod->dot_id : null;
        }

        if (!$periodId) {
            return ['rows' => [], 'total' => 0];
        }

        $period = Dot::find($periodId);
        if (!$period) {
            return ['rows' => [], 'total' => 0];
        }

        // Lấy danh sách lớp học tham gia đợt này
        $classIds = $period->lops()->pluck('lop.lop_id')->all();

        if (empty($classIds)) {
            return ['rows' => [], 'total' => 0];
        }

        // Truy vấn tất cả sinh viên thuộc các lớp này
        $students = SinhVien::with('lop')->whereIn('lop_id', $classIds)->get();

        // Lấy phân công theo đợt
        $assignments = PhanCongHdtt::with('giangVien')
            ->where('dot_id', $periodId)
            ->get()
            ->keyBy('sinh_vien_id');

        $rows = [];
        foreach ($students as $sv) {
            // Kiểm tra xem sinh viên đã có đăng ký thực tập được duyệt hay chưa
            $reg = DangKyThucTap::where('sinh_vien_id', $sv->sinh_vien_id)
                ->where('dot_id', $periodId)
                ->first();

            // Trạng thái tìm kiếm
            $status = 'not_registered';
            if ($reg && $reg->trang_thai === 'DA_DUYET') {
                $status = 'has_company';
            } elseif ($reg && ($reg->trang_thai === 'CHO_DUYET' || $reg->trang_thai === 'TU_CHOI')) {
                $status = 'searching';
            }

            $assign = $assignments->get($sv->sinh_vien_id);

            $rows[] = [
                'id' => (string)$sv->sinh_vien_id,
                'studentId' => $sv->ma_so_sinh_vien,
                'studentName' => $sv->ho_ten,
                'className' => $sv->lop ? $sv->lop->ten_lop : '',
                'phone' => $sv->so_dien_thoai ?? '',
                'status' => $status,
                'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                'assignmentStatus' => $assign ? 'assigned' : 'unassigned'
            ];
        }

        return [
            'rows' => $rows,
            'total' => count($rows)
        ];
    }

    /**
     * Xem chi tiết sinh viên chưa thực tập
     */
    public function getNoCompanyStudentDetail($id)
    {
        $sv = SinhVien::with('lop')->find($id);
        if (!$sv) {
            return null;
        }

        // Lấy đăng ký gần nhất của sinh viên
        $reg = DangKyThucTap::where('sinh_vien_id', $id)->orderBy('dang_ky_id', 'desc')->first();
        $status = 'not_registered';
        if ($reg && $reg->trang_thai === 'DA_DUYET') {
            $status = 'has_company';
        } elseif ($reg && ($reg->trang_thai === 'CHO_DUYET' || $reg->trang_thai === 'TU_CHOI')) {
            $status = 'searching';
        }

        $dotId = $reg ? $reg->dot_id : null;
        if (!$dotId) {
            $activePeriod = Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : null;
        }

        $assign = null;
        if ($dotId) {
            $assign = PhanCongHdtt::with('giangVien')
                ->where('sinh_vien_id', $id)
                ->where('dot_id', $dotId)
                ->first();
        }

        return [
            'id' => (string)$sv->sinh_vien_id,
            'studentId' => $sv->ma_so_sinh_vien,
            'studentName' => $sv->ho_ten,
            'className' => $sv->lop ? $sv->lop->ten_lop : '',
            'phone' => $sv->so_dien_thoai ?? '',
            'status' => $status,
            'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
            'assignmentStatus' => $assign ? 'assigned' : 'unassigned'
        ];
    }

    // ==========================================================
    // MAPPING HELPERS
    // ==========================================================

    /**
     * Map CongTy model sang Frontend structure
     */
    private function transformCompany($company)
    {
        $fields = DB::table('congtylinhvuc')
            ->where('cong_ty_id', $company->cong_ty_id)
            ->pluck('ten_linh_vuc')
            ->all();

        $studentsCount = DangKyThucTap::where('cong_ty_id', $company->cong_ty_id)
            ->where('trang_thai', 'DA_DUYET')
            ->count();

        $partnersCount = DangKyThucTap::where('cong_ty_id', $company->cong_ty_id)
            ->distinct()
            ->count('dot_id');

        $status = 'active';
        $reviewStatus = 'approved';

        if ($company->trang_thai === 'NGUNG_HOAT_DONG') {
            $status = 'paused';
            $reviewStatus = 'rejected';
        }

        return [
            'id' => (string)$company->cong_ty_id,
            'name' => $company->ten_cong_ty,
            'taxId' => $company->ma_so_thue ?? '',
            'field' => implode(', ', $fields),
            'contact' => $company->nguoi_lien_he ?? '',
            'phone' => $company->so_dien_thoai_lh ?? '',
            'email' => $company->email_lien_he ?? '',
            'partners' => $partnersCount,
            'students' => $studentsCount,
            'status' => $status,
            'reviewStatus' => $reviewStatus
        ];
    }

    /**
     * Map DangKyThucTap model sang ConfirmationRequest frontend structure
     */
    private function transformConfirmation($reg)
    {
        $status = 'pending';
        if ($reg->trang_thai === 'DA_DUYET') {
            $status = 'approved';
        } elseif ($reg->trang_thai === 'TU_CHOI') {
            $status = 'rejected';
        }

        return [
            'id' => (string)$reg->dang_ky_id,
            'studentId' => $reg->sinhVien ? $reg->sinhVien->ma_so_sinh_vien : '',
            'studentName' => $reg->sinhVien ? $reg->sinhVien->ho_ten : '',
            'className' => ($reg->sinhVien && $reg->sinhVien->lop) ? $reg->sinhVien->lop->ten_lop : '',
            'companyName' => $reg->congTy ? $reg->congTy->ten_cong_ty : '',
            'companyAddress' => $reg->congTy ? $reg->congTy->dia_chi : ($reg->dia_chi_thuc_tap ?? ''),
            'internshipLocation' => $reg->vi_tri_thuc_tap ?? '',
            'taxId' => $reg->congTy ? $reg->congTy->ma_so_thue : '',
            'mentor' => $reg->nguoi_huong_dan ?? '',
            'regDate' => '16/06/2026', // Mock registration date
            'status' => $status
        ];
    }

    private function mapFrontendStatusToBackend($status)
    {
        if ($status === 'active') {
            return 'HOAT_DONG';
        }
        return 'NGUNG_HOAT_DONG';
    }

    private function mapFrontendConfirmStatusToBackend($status)
    {
        if ($status === 'approved') {
            return 'DA_DUYET';
        } elseif ($status === 'rejected') {
            return 'TU_CHOI';
        }
        return 'CHO_DUYET';
    }
}
