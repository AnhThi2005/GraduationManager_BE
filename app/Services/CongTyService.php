<?php

namespace App\Services;

use App\Models\CongTy;
use App\Models\DangKyThucTap;
use App\Models\Dot;
use App\Models\PhanCongHdtt;
use App\Models\SinhVien;
use Illuminate\Support\Facades\DB;

class CongTyService
{
    // ==========================================================
    // 1. QUẢN LÝ DOANH NGHIỆP (COMPANIES CRUD)
    // ==========================================================

    /**
     * Lấy danh sách doanh nghiệp
     */
    public function getListCompany()
    {
        $companies = CongTy::orderBy('cong_ty_id', 'desc')->get();

        $rows = $companies->map(function ($company) {
            return $this->transformCompany($company);
        })->all();

        return [
            'rows' => $rows,
            'total' => count($rows),
        ];
    }

    /**
     * Xem chi tiết doanh nghiệp
     */
    public function getCompanyDetail($id)
    {
        $company = CongTy::find($id);
        if (! $company) {
            return null;
        }

        return $this->transformCompany($company);
    }

    /**
     * Tạo doanh nghiệp mới
     */
    public function createCompany(array $data)
    {
        $status = isset($data['status']) ? $this->mapFrontendStatusToBackend($data['status']) : 'CHO_DUYET';

        $company = CongTy::create([
            'ten_cong_ty' => $data['name'] ?? '',
            'dia_chi' => $data['companyAddress'] ?? $data['address'] ?? '',
            'ma_so_thue' => $data['taxId'] ?? '',
            'nguoi_lien_he' => $data['contact'] ?? '',
            'email_lien_he' => $data['email'] ?? '',
            'so_dien_thoai_lh' => $data['phone'] ?? '',
            'trang_thai' => $status,
        ]);

        $companyId = $company->cong_ty_id;

        // Lưu lĩnh vực hoạt động nếu có
        if (! empty($data['field'])) {
            $fields = array_map('trim', explode(',', $data['field']));
            foreach ($fields as $f) {
                DB::table('congtylinhvuc')->insert([
                    'cong_ty_id' => $companyId,
                    'ten_linh_vuc' => $f,
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
        if (! $company) {
            return null;
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['ten_cong_ty'] = $data['name'];
        }
        if (isset($data['address'])) {
            $updateData['dia_chi'] = $data['address'];
        }
        if (isset($data['companyAddress'])) {
            $updateData['dia_chi'] = $data['companyAddress'];
        }
        if (isset($data['taxId'])) {
            $updateData['ma_so_thue'] = $data['taxId'];
        }
        if (isset($data['contact'])) {
            $updateData['nguoi_lien_he'] = $data['contact'];
        }
        if (isset($data['email'])) {
            $updateData['email_lien_he'] = $data['email'];
        }
        if (isset($data['phone'])) {
            $updateData['so_dien_thoai_lh'] = $data['phone'];
        }
        if (isset($data['status'])) {
            $updateData['trang_thai'] = $this->mapFrontendStatusToBackend($data['status']);
        } elseif (isset($data['reviewStatus'])) {
            if ($data['reviewStatus'] === 'approved') {
                $updateData['trang_thai'] = 'HOAT_DONG';
            } elseif ($data['reviewStatus'] === 'rejected') {
                $updateData['trang_thai'] = 'NGUNG_HOAT_DONG';
            } else {
                $updateData['trang_thai'] = 'CHO_DUYET';
            }
        }

        $company->update($updateData);

        // Công ty được duyệt hoạt động KHÔNG có nghĩa là khai báo thực tập của sinh viên tại công ty đó
        // cũng được duyệt theo — đây là 2 quyết định độc lập, Admin vẫn phải tự duyệt từng khai báo.
        if ($company->trang_thai === 'NGUNG_HOAT_DONG') {
            // Công ty bị từ chối/tạm dừng: các khai báo đang chờ duyệt tại công ty này không thể được duyệt nữa
            // nên tự động từ chối theo. Không đụng tới khai báo đã duyệt/chờ cấp giấy (SV đã/đang thực tập thật)
            // vì đó là quyết định nghiệp vụ cần admin tự xử lý thủ công.
            DangKyThucTap::where('cong_ty_id', $id)
                ->where('trang_thai', 'CHO_DUYET')
                ->update(['trang_thai' => 'TU_CHOI']);
        }

        // Cập nhật lĩnh vực hoạt động
        if (isset($data['field'])) {
            DB::table('congtylinhvuc')->where('cong_ty_id', $id)->delete();
            if (! empty($data['field'])) {
                $fields = array_map('trim', explode(',', $data['field']));
                foreach ($fields as $f) {
                    DB::table('congtylinhvuc')->insert([
                        'cong_ty_id' => $id,
                        'ten_linh_vuc' => $f,
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
        if (! $company) {
            return false;
        }

        // Gỡ liên kết trong dangkythuctap
        DangKyThucTap::where('cong_ty_id', $id)->update(['cong_ty_id' => null]);

        // Xóa lĩnh vực hoạt động
        DB::table('congtylinhvuc')->where('cong_ty_id', $id)->delete();

        $company->delete();

        return true;
    }

    /**
     * Công bố danh sách công ty: công bố mọi công ty đang hoạt động mà chưa từng công bố.
     * Công ty đã công bố thì hiển thị vĩnh viễn cho sinh viên ở mọi đợt sau, không cần công bố lại.
     */
    public function publishCompanies()
    {
        $publishedCount = CongTy::where('trang_thai', 'HOAT_DONG')
            ->where('da_cong_bo', false)
            ->update(['da_cong_bo' => true]);

        return $publishedCount;
    }

    // ==========================================================
    // 2. KHAI BÁO THỰC TẬP (INTERNSHIP CONFIRMATIONS)
    // ==========================================================

    /**
     * Danh sách yêu cầu xác nhận thực tập tự tìm
     */
    public function getListConfirmationRequest(array $filters)
    {
        $query = DangKyThucTap::query()
            ->with(['sinhVien.lop', 'congTy'])
            ->whereNotNull('dia_chi_thuc_tap')
            ->where('dia_chi_thuc_tap', '!=', '');

        // Lọc theo đợt học
        if (! empty($filters['periodId'])) {
            $query->where('dot_id', $filters['periodId']);
        }

        $registrations = $query->get();

        $rows = $registrations->map(function ($reg) {
            return $this->transformConfirmation($reg);
        })->all();

        return [
            'rows' => $rows,
            'total' => count($rows),
        ];
    }

    /**
     * Danh sách yêu cầu khai báo thực tập tốt nghiệp (không lọc theo có hay không đăng ký cấp giấy)
     */
    public function getListDeclarations(array $filters)
    {
        $query = DangKyThucTap::query()
            ->with(['sinhVien.lop', 'congTy']);

        // Lọc theo đợt học
        if (! empty($filters['periodId'])) {
            $query->where('dot_id', $filters['periodId']);
        }

        $registrations = $query->get();

        $rows = $registrations->map(function ($reg) {
            return $this->transformConfirmation($reg);
        })->all();

        return [
            'rows' => $rows,
            'total' => count($rows),
        ];
    }

    /**
     * Chi tiết yêu cầu xác nhận thực tập
     */
    public function getConfirmationRequestDetail($id)
    {
        $reg = DangKyThucTap::with(['sinhVien.lop', 'congTy'])->find($id);
        if (! $reg) {
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
        if (! $student) {
            return null;
        }

        // Tìm hoặc tạo mới công ty
        $taxId = $data['taxId'] ?? '';
        $company = CongTy::where('ma_so_thue', $taxId)->first();
        if (! $company) {
            $company = CongTy::create([
                'ten_cong_ty' => $data['companyName'] ?? '',
                'dia_chi' => $data['companyAddress'] ?? '',
                'ma_so_thue' => $taxId,
                'nguoi_lien_he' => $data['mentor'] ?? '',
                'email_lien_he' => $data['email'] ?? '',
                'so_dien_thoai_lh' => $data['phone'] ?? '',
                'trang_thai' => 'CHO_DUYET', // Công ty mới do admin nhập, chờ được duyệt làm đối tác chính thức
            ]);
        }

        $dotId = $periodId ?? $data['periodId'] ?? null;
        if (! $dotId) {
            // Lấy đợt TTTN đang mở làm mặc định
            $activePeriod = Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
            $dotId = $activePeriod ? $activePeriod->dot_id : 1;
        }

        $dot = Dot::find($dotId);
        if (! $dot) {
            throw new \InvalidArgumentException('Không tìm thấy đợt thực tập tốt nghiệp này!');
        }
        if (! $dot->hasStudent($student->sinh_vien_id)) {
            throw new \InvalidArgumentException(
                "Sinh viên {$student->ma_so_sinh_vien} không thuộc đợt \"{$dot->ten_dot}\" (lớp chưa được gắn vào đợt này). Vui lòng thêm sinh viên vào đợt trước khi khai báo."
            );
        }

        $reg = DangKyThucTap::create([
            'sinh_vien_id' => $student->sinh_vien_id,
            'dot_id' => $dotId,
            'cong_ty_id' => $company->cong_ty_id,
            'nguoi_huong_dan' => $data['mentor'] ?? '',
            'sdt_huong_dan' => $data['phone'] ?? '',
            'vi_tri_thuc_tap' => $data['field'] ?? '',
            'vi_tri_cong_viec' => $data['position'] ?? $data['vi_tri_cong_viec'] ?? '',
            'thoi_gian_thuc_tap' => ($data['thoi_gian_thuc_tap'] ?? null) ?: $dot->moTaThoiGianThucTap(),
            'dia_chi_thuc_tap' => $data['internshipLocation'] ?? '',
            'trang_thai' => 'CHO_DUYET',
            'ngay_dang_ky' => now(),
        ]);

        return $this->getConfirmationRequestDetail($reg->dang_ky_id);
    }

    /**
     * Phê duyệt hoặc từ chối yêu cầu tự khai báo
     */
    public function updateConfirmationRequest($id, array $data)
    {
        $reg = DangKyThucTap::find($id);
        if (! $reg) {
            return null;
        }

        $updateData = [];
        if (isset($data['status'])) {
            $status = $this->mapFrontendConfirmStatusToBackend($data['status']);
            if ($status === 'DA_DUYET') {
                if ($reg->trang_thai === 'CHO_DUYET' && ! empty($reg->dia_chi_thuc_tap)) {
                    $status = 'CHO_CAP_GIAY';
                }
            }
            $updateData['trang_thai'] = $status;
        }
        if (isset($data['mentor'])) {
            $updateData['nguoi_huong_dan'] = $data['mentor'];
        }
        if (isset($data['phone'])) {
            $updateData['sdt_huong_dan'] = $data['phone'];
        }
        if (isset($data['internshipLocation'])) {
            $updateData['dia_chi_thuc_tap'] = $data['internshipLocation'];
        }
        if (isset($data['position'])) {
            $updateData['vi_tri_cong_viec'] = $data['position'];
        }
        if (isset($data['companyAddress']) && $reg->congTy) {
            $reg->congTy->update(['dia_chi' => $data['companyAddress']]);
        }

        $reg->update($updateData);

        return $this->getConfirmationRequestDetail($id);
    }

    /**
     * Xóa yêu cầu tự khai báo
     */
    public function deleteConfirmationRequest($id)
    {
        $reg = DangKyThucTap::find($id);
        if (! $reg) {
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
        if (! $periodId) {
            // Lấy đợt TTTN mới nhất
            $activePeriod = Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
            $periodId = $activePeriod ? $activePeriod->dot_id : null;
        }

        if (! $periodId) {
            return ['rows' => [], 'total' => 0];
        }

        $period = Dot::find($periodId);
        if (! $period) {
            return ['rows' => [], 'total' => 0];
        }

        // Sinh viên thuộc đợt này: qua lớp được gắn vào đợt (dot_lop),
        // hoặc được thêm thủ công vào đợt (dot_sinhvien) — đồng bộ với Dot::hasStudent()
        // dùng ở TrangChuController và các endpoint tạo khai báo/xác nhận thực tập.
        $classIds = $period->lops()->pluck('lop.lop_id')->all();
        $manualStudentIds = $period->sinhViens()->pluck('sinhvien.sinh_vien_id')->all();

        if (empty($classIds) && empty($manualStudentIds)) {
            return ['rows' => [], 'total' => 0];
        }

        // Chỉ lấy tài khoản đang hoạt động (loại tài khoản đã bị khóa/xóa mềm)
        $students = SinhVien::with('lop')
            ->where('dang_hoat_dong', 1)
            ->where(function ($q) use ($classIds, $manualStudentIds) {
                if (! empty($classIds)) {
                    $q->orWhereIn('lop_id', $classIds);
                }
                if (! empty($manualStudentIds)) {
                    $q->orWhereIn('sinh_vien_id', $manualStudentIds);
                }
            })
            ->get();

        // Đăng ký thực tập trong đợt này
        $registrations = DangKyThucTap::where('dot_id', $periodId)->get()->keyBy('sinh_vien_id');

        // Lấy phân công theo đợt
        $assignments = PhanCongHdtt::with('giangVien')
            ->where('dot_id', $periodId)
            ->get()
            ->keyBy('sinh_vien_id');

        $rows = [];
        foreach ($students as $sv) {
            // Kiểm tra xem sinh viên đã có đăng ký thực tập được duyệt hay chưa
            $reg = $registrations->get($sv->sinh_vien_id);

            // Trạng thái tìm kiếm
            $status = 'not_registered';
            if ($reg && ($reg->trang_thai === 'DA_DUYET' || $reg->trang_thai === 'CHO_CAP_GIAY')) {
                $status = 'has_company';
            }

            $assign = $assignments->get($sv->sinh_vien_id);

            $rows[] = [
                'id' => (string) $sv->sinh_vien_id,
                'studentId' => $sv->ma_so_sinh_vien,
                'studentName' => $sv->ho_ten,
                'className' => $sv->lop ? $sv->lop->ten_lop : '',
                'phone' => $sv->so_dien_thoai ?? '',
                'status' => $status,
                'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
                'assignmentStatus' => $assign ? 'assigned' : 'unassigned',
            ];
        }

        return [
            'rows' => $rows,
            'total' => count($rows),
        ];
    }

    /**
     * Xem chi tiết sinh viên chưa thực tập
     */
    public function getNoCompanyStudentDetail($id, $periodId = null)
    {
        $sv = SinhVien::with('lop')->find($id);
        if (! $sv) {
            return null;
        }

        $dotId = $periodId;
        if (! $dotId) {
            // Tìm đợt TTTN đang hoạt động liên kết với lớp của sinh viên
            $lopId = $sv->lop_id;
            $activePeriod = Dot::where('loai_dot', 'TTTN')
                ->where('trang_thai', '!=', 'DA_DONG')
                ->whereHas('lops', function ($q) use ($lopId) {
                    $q->where('lop.lop_id', $lopId);
                })->orderBy('dot_id', 'desc')->first();

            $dotId = $activePeriod ? $activePeriod->dot_id : null;
            if (! $dotId) {
                $newestPeriod = Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
                $dotId = $newestPeriod ? $newestPeriod->dot_id : null;
            }
        }

        // Lấy đăng ký của sinh viên trong đợt này
        $reg = null;
        if ($dotId) {
            $reg = DangKyThucTap::where('sinh_vien_id', $id)
                ->where('dot_id', $dotId)
                ->first();
        }

        $status = 'not_registered';
        if ($reg && ($reg->trang_thai === 'DA_DUYET' || $reg->trang_thai === 'CHO_CAP_GIAY')) {
            $status = 'has_company';
        }

        $assign = null;
        if ($dotId) {
            $assign = PhanCongHdtt::with('giangVien')
                ->where('sinh_vien_id', $id)
                ->where('dot_id', $dotId)
                ->first();
        }

        $companyName = '—';
        $internshipLocation = '—';
        if ($reg) {
            $companyName = $reg->congTy ? $reg->congTy->ten_cong_ty : ($reg->dia_chi_thuc_tap ?? '—');
            $internshipLocation = $reg->dia_chi_thuc_tap ?? '—';
        }

        return [
            'id' => (string) $sv->sinh_vien_id,
            'studentId' => $sv->ma_so_sinh_vien,
            'studentName' => $sv->ho_ten,
            'className' => $sv->lop ? $sv->lop->ten_lop : '',
            'phone' => $sv->so_dien_thoai ?? '',
            'status' => $status,
            'supervisor' => $assign ? $assign->giangVien->ho_ten : null,
            'assignmentStatus' => $assign ? 'assigned' : 'unassigned',
            'companyName' => $companyName,
            'internshipLocation' => $internshipLocation,
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

        if ($company->trang_thai === 'CHO_DUYET') {
            $status = 'pending';
            $reviewStatus = 'pending';
        } elseif ($company->trang_thai === 'NGUNG_HOAT_DONG') {
            $status = 'paused';
            $reviewStatus = 'rejected';
        }

        return [
            'id' => (string) $company->cong_ty_id,
            'name' => $company->ten_cong_ty,
            'taxId' => $company->ma_so_thue ?? '',
            'field' => implode(', ', $fields),
            'contact' => $company->nguoi_lien_he ?? '',
            'phone' => $company->so_dien_thoai_lh ?? '',
            'email' => $company->email_lien_he ?? '',
            'partners' => $partnersCount,
            'students' => $studentsCount,
            'status' => $status,
            'reviewStatus' => $reviewStatus,
            'published' => (bool) $company->da_cong_bo,
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
        } elseif ($reg->trang_thai === 'CHO_CAP_GIAY') {
            $status = 'cho_cap_giay';
        }

        // Giảng viên hướng dẫn (GVHD) do trường phân công cho sinh viên trong đợt này —
        // dùng để in vào giấy giới thiệu, khác với "mentor" (người hướng dẫn phía công ty).
        $gvhdName = '';
        if ($reg->sinhVien) {
            $phanCong = DB::table('phanconghdtt')
                ->join('giangvien', 'phanconghdtt.giang_vien_id', '=', 'giangvien.giang_vien_id')
                ->where('phanconghdtt.sinh_vien_id', $reg->sinhVien->sinh_vien_id)
                ->where('phanconghdtt.dot_id', $reg->dot_id)
                ->where('phanconghdtt.da_cong_bo', true)
                ->whereNull('phanconghdtt.deleted_at')
                ->select('giangvien.ho_ten', 'giangvien.hoc_vi')
                ->first();
            if ($phanCong) {
                $gvhdName = ($phanCong->hoc_vi ? $phanCong->hoc_vi.'. ' : '').$phanCong->ho_ten;
            }
        }

        return [
            'id' => (string) $reg->dang_ky_id,
            'studentId' => $reg->sinhVien ? $reg->sinhVien->ma_so_sinh_vien : '',
            'studentName' => $reg->sinhVien ? $reg->sinhVien->ho_ten : '',
            'studentPhone' => $reg->sinhVien ? ($reg->sinhVien->so_dien_thoai ?? '') : '',
            'className' => ($reg->sinhVien && $reg->sinhVien->lop) ? $reg->sinhVien->lop->ten_lop : '',
            'companyName' => $reg->congTy ? $reg->congTy->ten_cong_ty : '',
            'companyAddress' => $reg->congTy ? $reg->congTy->dia_chi : '',
            'internshipLocation' => $reg->dia_chi_thuc_tap ?? '',
            'position' => $reg->vi_tri_cong_viec ?? '',
            'taxId' => $reg->congTy ? $reg->congTy->ma_so_thue : '',
            'mentor' => $reg->nguoi_huong_dan ?? '',
            'gvhdName' => $gvhdName,
            'regDate' => $reg->ngay_dang_ky ? $reg->ngay_dang_ky->format('d/m/Y') : '',
            'status' => $status,
        ];
    }

    private function mapFrontendStatusToBackend($status)
    {
        if ($status === 'active') {
            return 'HOAT_DONG';
        }
        if ($status === 'pending') {
            return 'CHO_DUYET';
        }

        return 'NGUNG_HOAT_DONG';
    }

    private function mapFrontendConfirmStatusToBackend($status)
    {
        if ($status === 'approved') {
            return 'DA_DUYET';
        } elseif ($status === 'rejected') {
            return 'TU_CHOI';
        } elseif ($status === 'cho_cap_giay') {
            return 'CHO_CAP_GIAY';
        }

        return 'CHO_DUYET';
    }

    /**
     * Cập nhật trạng thái sinh viên chưa có nơi thực tập
     */
    public function updateNoCompanyStudentStatus($sinhVienId, $status, $periodId = null)
    {
        $sv = SinhVien::find($sinhVienId);
        if (! $sv) {
            return null;
        }

        $dotId = $periodId;
        if (! $dotId) {
            // Tìm đợt TTTN đang hoạt động liên kết với lớp của sinh viên
            $lopId = $sv->lop_id;
            $activePeriod = Dot::where('loai_dot', 'TTTN')
                ->where('trang_thai', '!=', 'DA_DONG')
                ->whereHas('lops', function ($q) use ($lopId) {
                    $q->where('lop.lop_id', $lopId);
                })->orderBy('dot_id', 'desc')->first();

            $dotId = $activePeriod ? $activePeriod->dot_id : null;
            if (! $dotId) {
                $newestPeriod = Dot::where('loai_dot', 'TTTN')->orderBy('dot_id', 'desc')->first();
                $dotId = $newestPeriod ? $newestPeriod->dot_id : null;
            }
        }

        if ($dotId) {
            $reg = DangKyThucTap::where('sinh_vien_id', $sinhVienId)
                ->where('dot_id', $dotId)
                ->first();

            if ($reg) {
                if ($status === 'has_company') {
                    $reg->update(['trang_thai' => 'DA_DUYET']);
                } elseif ($status === 'not_registered') {
                    $reg->update(['trang_thai' => 'TU_CHOI']);
                }
            }
        }

        return $this->getNoCompanyStudentDetail($sinhVienId, $dotId);
    }
}
