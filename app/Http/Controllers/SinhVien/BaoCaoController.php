<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Concerns\KiemTraTrangThaiDot;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BaoCaoTienDo;
use App\Models\Dot;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BaoCaoController extends Controller
{
    use KiemTraTrangThaiDot;

    /**
     * Lấy danh sách báo cáo tiến độ TTTN của sinh viên
     */
    public function layDanhSachBaoCaoTttn(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        // Lấy đợt TTTN đang diễn ra của sinh viên
        $lopId = $sinhVien->lop_id;
        $activePeriod = Dot::where('loai_dot', 'TTTN')
            ->whereHas('lops', function($q) use ($lopId) {
                $q->where('lop.lop_id', $lopId);
            })->orderBy('dot_id', 'desc')->first();

        if (!$activePeriod) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'objects' => [],
                    'hasGvhd' => true
                ]
            ]);
        }

        // Kiểm tra xem sinh viên đã khai báo thực tập và được duyệt chưa
        $registration = DB::table('dangkythuctap')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->first();

        $isInternshipApproved = $registration && $registration->trang_thai === 'DA_DUYET';

        // Kiểm tra xem sinh viên đã được phân công Giảng viên hướng dẫn (GVHD) chưa
        $coGvhd = DB::table('phanconghdtt')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->where('da_cong_bo', true)
            ->whereNull('deleted_at')
            ->exists();

        if (!$coGvhd) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'objects' => [],
                    'hasGvhd' => false,
                    'isInternshipApproved' => $isInternshipApproved
                ]
            ]);
        }

        $start = Carbon::parse($activePeriod->ngay_bat_dau, 'Asia/Ho_Chi_Minh');
        $now = Carbon::now();

        $reports = BaoCaoTienDo::where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->where('loai_bao_cao', 'THUC_TAP')
            ->get()
            ->keyBy('tuan_so');

        // Lấy tất cả gia hạn cho sinh viên này trong đợt này
        $extensions = DB::table('gia_han_nop_bao_cao')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->where('loai_bao_cao', 'THUC_TAP')
            ->get()
            ->keyBy('tuan');

        $formatted = [];
        $totalWeeks = $activePeriod->tinhSoTuan();
        $batchDeadline = $activePeriod->han_nop_bao_cao 
            ? Carbon::parse($activePeriod->han_nop_bao_cao)->endOfDay() 
            : Carbon::parse($activePeriod->ngay_ket_thuc)->endOfDay();

        for ($w = 1; $w <= $totalWeeks; $w++) {
            $r = $reports->get($w);
            $extension = $extensions->get($w);
            $appliedDeadline = $extension ? Carbon::parse($extension->han_nop_moi)->endOfDay() : $batchDeadline;

            if ($r) {
                // Tách title và note từ cột noi_dung
                $noiDung = $r->noi_dung ?? '';
                $parts = explode("\n", $noiDung, 2);
                $title = $parts[0] ?? 'Chưa có tiêu đề';
                $note = $parts[1] ?? '';

                // Định dạng ngày cập nhật
                $updatedAt = $r->thoi_gian_nop ? Carbon::parse($r->thoi_gian_nop)->format('d/m/Y') : '—';

                // Lấy nhận xét từ GV
                $comment = DB::table('nhanxetbaocao')->where('bao_cao_id', $r->bao_cao_id)->first();

                $fileUrl = $this->resolveFileUrl($r->duong_dan_file);

                $formatted[] = [
                    'week' => $w,
                    'title' => $title,
                    'status' => 'Đã nộp',
                    'file' => $r->ten_file_goc ?: ($r->duong_dan_file ? basename($r->duong_dan_file) : '—'),
                    'fileUrl' => $fileUrl,
                    'note' => $note,
                    'teacherComment' => $comment ? $comment->noi_dung : null,
                    'updated' => $updatedAt
                ];
            } else {
                // Chưa nộp: kiểm tra xem đã quá hạn nộp chưa
                $status = $now->gt($appliedDeadline) ? 'Thiếu' : 'Chưa nộp';

                $formatted[] = [
                    'week' => $w,
                    'title' => 'Chưa nộp báo cáo',
                    'status' => $status,
                    'file' => '—',
                    'fileUrl' => null,
                    'note' => 'Sinh viên chưa nộp báo cáo tuần này.',
                    'teacherComment' => null,
                    'updated' => '—'
                ];
            }
        }

        // Sắp xếp các tuần theo thứ tự tăng dần
        usort($formatted, function($a, $b) {
            return $a['week'] <=> $b['week'];
        });

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $formatted,
                'hasGvhd' => true,
                'isInternshipApproved' => $isInternshipApproved
            ]
        ]);
    }

    /**
     * Nộp nhật ký báo cáo tiến độ TTTN
     */
    public function nopBaoCaoTttn(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        $request->validate([
            'week' => 'required|integer|min:1',
            'title' => 'required|string|max:255',
            'note' => 'required|string',
            'file' => 'nullable|string|max:500',
            'fileName' => 'nullable|string|max:255'
        ]);

        $week = (int)$request->input('week');
        $title = trim($request->input('title'));
        $note = trim($request->input('note'));
        $filePath = $request->input('file') ?: null;
        $fileOriginalName = $request->input('fileName') ?: null;

        // Lấy đợt TTTN đang diễn ra của sinh viên
        $lopId = $sinhVien->lop_id;
        $activePeriod = Dot::where('loai_dot', 'TTTN')
            ->whereHas('lops', function($q) use ($lopId) {
                $q->where('lop.lop_id', $lopId);
            })->orderBy('dot_id', 'desc')->first();

        if (!$activePeriod) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đợt thực tập tốt nghiệp hiện tại.'], 400);
        }

        if ($resp = $this->chanNeuSinhVienKhongDuocSua($activePeriod)) {
            return $resp;
        }

        // Kiểm tra xem sinh viên đã khai báo thực tập và được duyệt chưa
        $registration = DB::table('dangkythuctap')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->first();

        if (!$registration || $registration->trang_thai !== 'DA_DUYET') {
            return response()->json([
                'success' => false,
                'message' => 'Thông tin đăng ký thực tập tốt nghiệp của bạn chưa được duyệt hoặc chưa khai báo. Bạn không thể thực hiện nộp báo cáo!'
            ], 403);
        }

        // Kiểm tra xem sinh viên đã được phân công Giảng viên hướng dẫn (GVHD) chưa
        $coGvhd = DB::table('phanconghdtt')
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->where('da_cong_bo', true)
            ->whereNull('deleted_at')
            ->exists();

        if (!$coGvhd) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa được phân công Giảng viên hướng dẫn cho đợt này. Bạn không thể thực hiện nộp báo cáo!'
            ], 403);
        }

        // Chặn nộp tuần vượt quá số tuần quy định
        $totalWeeks = $activePeriod->tinhSoTuan();
        if ($week > $totalWeeks) {
            return response()->json([
                'success' => false,
                'message' => "Tuần {$week} vượt quá số tuần của đợt thực tập này (Tối đa {$totalWeeks} tuần)."
            ], 400);
        }

        // Chặn nộp trước các tuần ở tương lai
        $startOfWeek = Carbon::parse($activePeriod->ngay_bat_dau, 'Asia/Ho_Chi_Minh')->addWeeks($week - 1);
        if (Carbon::now()->lt($startOfWeek)) {
            return response()->json([
                'success' => false,
                'message' => "Tuần {$week} chưa bắt đầu. Bạn chưa thể nộp báo cáo cho tuần này!"
            ], 400);
        }

        // Kiểm tra thời hạn nộp báo cáo
        $extension = DB::table('gia_han_nop_bao_cao')
            ->where([
                'sinh_vien_id' => $sinhVien->sinh_vien_id,
                'dot_id' => $activePeriod->dot_id,
                'loai_bao_cao' => 'THUC_TAP',
                'tuan' => $week
            ])->first();

        $batchDeadline = $activePeriod->han_nop_bao_cao 
            ? Carbon::parse($activePeriod->han_nop_bao_cao)->endOfDay() 
            : Carbon::parse($activePeriod->ngay_ket_thuc)->endOfDay();
        $appliedDeadline = $extension ? Carbon::parse($extension->han_nop_moi)->endOfDay() : $batchDeadline;

        if (Carbon::now()->gt($appliedDeadline)) {
            return response()->json([
                'success' => false,
                'message' => "Đã hết hạn nộp hoặc cập nhật báo cáo tuần {$week} (Hạn nộp đợt: " . $appliedDeadline->copy()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') . ")."
            ], 400);
        }

        $noiDungStr = $title . "\n" . $note;

        // Giữ lại file cũ nếu lần nộp này không đính kèm file mới
        $existing = BaoCaoTienDo::where([
            'sinh_vien_id' => $sinhVien->sinh_vien_id,
            'dot_id' => $activePeriod->dot_id,
            'tuan_so' => $week,
            'loai_bao_cao' => 'THUC_TAP'
        ])->first();

        $filePath = $filePath ?? ($existing->duong_dan_file ?? null);
        $fileOriginalName = $fileOriginalName ?? ($existing->ten_file_goc ?? null);

        // Lưu hoặc cập nhật báo cáo tuần của sinh viên trong đợt này
        $report = BaoCaoTienDo::updateOrCreate([
            'sinh_vien_id' => $sinhVien->sinh_vien_id,
            'dot_id' => $activePeriod->dot_id,
            'tuan_so' => $week,
            'loai_bao_cao' => 'THUC_TAP'
        ], [
            'noi_dung' => $noiDungStr,
            'duong_dan_file' => $filePath,
            'ten_file_goc' => $fileOriginalName,
            'trang_thai' => 'DA_NOP',
            'thoi_gian_nop' => Carbon::now()->toDateTimeString()
        ]);

        $fileUrl = $this->resolveFileUrl($report->duong_dan_file);

        return response()->json([
            'code' => 200,
            'message' => 'Nộp báo cáo thực tập thành công!',
            'results' => [
                'object' => [
                    'week' => (int)$report->tuan_so,
                    'title' => $title,
                    'status' => 'Đã nộp',
                    'file' => $report->ten_file_goc ?: ($report->duong_dan_file ? basename($report->duong_dan_file) : '—'),
                    'fileUrl' => $fileUrl,
                    'note' => $note,
                    'teacherComment' => null,
                    'updated' => Carbon::parse($report->thoi_gian_nop)->format('d/m/Y')
                ]
            ]
        ]);
    }

    /**
     * Lấy danh sách báo cáo tiến độ ĐATN của sinh viên
     */
    public function layDanhSachBaoCaoDatn(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        // Tìm nhóm ĐATN đã được duyệt và có đề tài của sinh viên
        $nhom = DB::table('nhomsvda')
            ->join('thanhviennhom', 'nhomsvda.nhom_id', '=', 'thanhviennhom.nhom_id')
            ->where('thanhviennhom.sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('nhomsvda.trang_thai_duyet', 'DA_DUYET')
            ->whereNotNull('nhomsvda.de_tai_id')
            ->orderBy('nhomsvda.dot_id', 'desc')
            ->select('nhomsvda.*')
            ->first();

        if (!$nhom) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'objects' => [],
                    'isTopicApproved' => false
                ]
            ]);
        }

        $activePeriod = Dot::find($nhom->dot_id);
        if (!$activePeriod) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'objects' => []
                ]
            ]);
        }

        $start = Carbon::parse($activePeriod->ngay_bat_dau, 'Asia/Ho_Chi_Minh');
        $now = Carbon::now();

        // Lấy danh sách ID của tất cả thành viên trong nhóm
        $memberIds = DB::table('thanhviennhom')
            ->where('nhom_id', $nhom->nhom_id)
            ->pluck('sinh_vien_id')
            ->all();

        $reports = BaoCaoTienDo::whereIn('sinh_vien_id', $memberIds)
            ->where('dot_id', $nhom->dot_id)
            ->where('loai_bao_cao', 'DO_AN')
            ->get()
            ->keyBy('tuan_so');

        // Lấy tất cả gia hạn cho nhóm này trong đợt này
        $extensions = DB::table('gia_han_nop_bao_cao')
            ->whereIn('sinh_vien_id', $memberIds)
            ->where('dot_id', $nhom->dot_id)
            ->where('loai_bao_cao', 'DO_AN')
            ->get()
            ->keyBy('tuan');

        $formatted = [];
        $totalWeeks = $activePeriod->tinhSoTuan();
        $batchDeadline = $activePeriod->han_nop_bao_cao 
            ? Carbon::parse($activePeriod->han_nop_bao_cao)->endOfDay() 
            : Carbon::parse($activePeriod->ngay_ket_thuc)->endOfDay();

        for ($w = 1; $w <= $totalWeeks; $w++) {
            $r = $reports->get($w);
            $extension = $extensions->get($w);
            $appliedDeadline = $extension ? Carbon::parse($extension->han_nop_moi)->endOfDay() : $batchDeadline;

            if ($r) {
                // Tách name, note từ cột noi_dung
                $noiDung = $r->noi_dung ?? '';
                $parts = explode("\n", $noiDung, 2);
                $name = $parts[0] ?? 'Chưa đặt tên';
                $note = $parts[1] ?? '';

                // Định dạng ngày cập nhật
                $updatedAt = $r->thoi_gian_nop ? Carbon::parse($r->thoi_gian_nop)->format('d/m/Y') : '—';

                // Lấy nhận xét từ GV
                $comment = DB::table('nhanxetbaocao')->where('bao_cao_id', $r->bao_cao_id)->first();

                $fileUrl = $this->resolveFileUrl($r->duong_dan_file);

                $formatted[] = [
                    'week' => $w,
                    'name' => $name,
                    'status' => 'Đã nộp',
                    'file' => $r->ten_file_goc ?: ($r->duong_dan_file ? basename($r->duong_dan_file) : '—'),
                    'fileUrl' => $fileUrl,
                    'note' => $note,
                    'teacherComment' => $comment ? $comment->noi_dung : null,
                    'updated' => $updatedAt
                ];
            } else {
                // Chưa nộp: kiểm tra xem đã quá hạn nộp chưa
                $status = $now->gt($appliedDeadline) ? 'Thiếu' : 'Chưa nộp';

                $formatted[] = [
                    'week' => $w,
                    'name' => 'Chưa nộp bản thảo',
                    'status' => $status,
                    'file' => '—',
                    'fileUrl' => null,
                    'note' => 'Nhóm chưa nộp bản thảo tuần này.',
                    'teacherComment' => null,
                    'updated' => '—'
                ];
            }
        }

        // Sắp xếp các tuần theo thứ tự tăng dần
        usort($formatted, function($a, $b) {
            return $a['week'] <=> $b['week'];
        });

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $formatted,
                'isTopicApproved' => true
            ]
        ]);
    }

    /**
     * Nộp bản thảo báo cáo tiến độ ĐATN
     */
    public function nopBaoCaoDatn(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        $request->validate([
            'week' => 'required|integer|min:1',
            'name' => 'required|string|max:255',
            'note' => 'required|string',
            'file' => 'nullable|string',
            'fileName' => 'nullable|string|max:255'
        ]);

        $week = (int)$request->input('week');
        $name = trim($request->input('name'));
        $note = trim($request->input('note'));
        $file = $request->input('file');
        $fileOriginalName = $request->input('fileName') ?: null;

        // Tìm nhóm ĐATN đã được duyệt và có đề tài của sinh viên
        $nhom = DB::table('nhomsvda')
            ->join('thanhviennhom', 'nhomsvda.nhom_id', '=', 'thanhviennhom.nhom_id')
            ->where('thanhviennhom.sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('nhomsvda.trang_thai_duyet', 'DA_DUYET')
            ->whereNotNull('nhomsvda.de_tai_id')
            ->orderBy('nhomsvda.dot_id', 'desc')
            ->select('nhomsvda.*')
            ->first();

        if (!$nhom) {
            return response()->json([
                'success' => false,
                'message' => 'Đề tài tốt nghiệp của bạn chưa được duyệt hoặc đã bị từ chối. Bạn không thể thực hiện nộp báo cáo tiến độ!'
            ], 403);
        }

        $activePeriod = Dot::find($nhom->dot_id);
        if (!$activePeriod) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đợt tốt nghiệp tương ứng.'], 400);
        }

        if ($resp = $this->chanNeuSinhVienKhongDuocSua($activePeriod)) {
            return $resp;
        }

        // Chặn nộp tuần vượt quá số tuần quy định
        $totalWeeks = $activePeriod->tinhSoTuan();
        if ($week > $totalWeeks) {
            return response()->json([
                'success' => false,
                'message' => "Tuần {$week} vượt quá số tuần của đợt đồ án tốt nghiệp này (Tối đa {$totalWeeks} tuần)."
            ], 400);
        }

        // Chặn nộp trước các tuần ở tương lai
        $startOfWeek = Carbon::parse($activePeriod->ngay_bat_dau, 'Asia/Ho_Chi_Minh')->addWeeks($week - 1);
        if (Carbon::now()->lt($startOfWeek)) {
            return response()->json([
                'success' => false,
                'message' => "Tuần {$week} chưa bắt đầu. Bạn chưa thể nộp báo cáo cho tuần này!"
            ], 400);
        }

        // Lấy danh sách ID của tất cả thành viên trong nhóm để kiểm tra/nộp chung báo cáo nhóm
        $memberIds = DB::table('thanhviennhom')
            ->where('nhom_id', $nhom->nhom_id)
            ->pluck('sinh_vien_id')
            ->all();

        // Kiểm tra thời hạn nộp báo cáo ĐATN (cho cả nhóm)
        $extension = DB::table('gia_han_nop_bao_cao')
            ->whereIn('sinh_vien_id', $memberIds)
            ->where('dot_id', $nhom->dot_id)
            ->where('loai_bao_cao', 'DO_AN')
            ->where('tuan', $week)
            ->first();

        $batchDeadline = $activePeriod->han_nop_bao_cao 
            ? Carbon::parse($activePeriod->han_nop_bao_cao)->endOfDay() 
            : Carbon::parse($activePeriod->ngay_ket_thuc)->endOfDay();
        $appliedDeadline = $extension ? Carbon::parse($extension->han_nop_moi)->endOfDay() : $batchDeadline;

        if (Carbon::now()->gt($appliedDeadline)) {
            return response()->json([
                'success' => false,
                'message' => "Đã hết hạn nộp hoặc cập nhật báo cáo bản thảo tuần {$week} (Hạn nộp đợt: " . $appliedDeadline->copy()->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') . ")."
            ], 400);
        }

        $noiDungStr = $name . "\n" . $note;

        // Tìm xem tuần này đã có báo cáo của bất kỳ thành viên nào trong nhóm chưa (giống cơ chế bên TTTN)
        $report = BaoCaoTienDo::whereIn('sinh_vien_id', $memberIds)
            ->where('dot_id', $nhom->dot_id)
            ->where('tuan_so', $week)
            ->where('loai_bao_cao', 'DO_AN')
            ->first();

        if (!$report) {
            $report = BaoCaoTienDo::create([
                'sinh_vien_id' => $sinhVien->sinh_vien_id,
                'dot_id' => $nhom->dot_id,
                'tuan_so' => $week,
                'loai_bao_cao' => 'DO_AN',
                'noi_dung' => $noiDungStr,
                'duong_dan_file' => $file ?: null,
                'ten_file_goc' => $fileOriginalName,
                'trang_thai' => 'DA_NOP',
                'thoi_gian_nop' => Carbon::now()->toDateTimeString()
            ]);
        } else {
            // Cập nhật bản ghi cũ, giữ lại tên file gốc cũ nếu lần nộp này không đính kèm file mới
            $report->update([
                'noi_dung' => $noiDungStr,
                'duong_dan_file' => $file ?? $report->duong_dan_file,
                'ten_file_goc' => $fileOriginalName ?? $report->ten_file_goc,
                'trang_thai' => 'DA_NOP',
                'thoi_gian_nop' => Carbon::now()->toDateTimeString()
            ]);
        }

        $fileUrl = $this->resolveFileUrl($report->duong_dan_file);

        return response()->json([
            'code' => 200,
            'message' => 'Nộp bản thảo đồ án thành công!',
            'results' => [
                'object' => [
                    'week' => $week,
                    'name' => $name,
                    'status' => 'Đã nộp',
                    'file' => $report->ten_file_goc ?: ($report->duong_dan_file ? basename($report->duong_dan_file) : '—'),
                    'fileUrl' => $fileUrl,
                    'note' => $note,
                    'teacherComment' => null,
                    'updated' => Carbon::parse($report->thoi_gian_nop)->format('d/m/Y')
                ]
            ]
        ]);
    }

    private function resolveFileUrl($path)
    {
        if (!$path || $path === '—') {
            return null;
        }
        if (str_starts_with($path, 'http')) {
            $parsedPath = parse_url($path, PHP_URL_PATH);
            return rtrim(request()->schemeAndHttpHost(), '/') . $parsedPath;
        }
        return rtrim(request()->schemeAndHttpHost(), '/') . '/storage/' . $path;
    }
}
