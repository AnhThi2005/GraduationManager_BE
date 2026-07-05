<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\BaoCaoTienDo;
use App\Models\Dot;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class BaoCaoController extends Controller
{
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
                    'objects' => []
                ]
            ]);
        }

        $reports = BaoCaoTienDo::where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->where('loai_bao_cao', 'THUC_TAP')
            ->orderBy('thoi_gian_nop', 'desc')
            ->get();

        $formatted = $reports->map(function ($r) {
            // Tách title và note từ cột noi_dung
            $noiDung = $r->noi_dung ?? '';
            $parts = explode("\n", $noiDung, 2);
            $title = $parts[0] ?? 'Chưa có tiêu đề';
            $note = $parts[1] ?? '';

            // Định dạng ngày cập nhật
            $updatedAt = $r->thoi_gian_nop ? Carbon::parse($r->thoi_gian_nop)->format('d/m/Y') : '—';

            // Lấy nhận xét từ GV để xác định trạng thái duyệt
            $comment = DB::table('nhanxetbaocao')->where('bao_cao_id', $r->bao_cao_id)->first();

            // Map trạng thái sang chuẩn Frontend mong đợi
            $status = 'Chờ duyệt';
            if ($comment) {
                if ($comment->danh_gia === 'DAT') {
                    $status = 'Đã duyệt';
                } else if ($comment->danh_gia === 'CHUA_DAT') {
                    $status = 'Bị từ chối';
                }
            }

            $fileUrl = null;
            if ($r->duong_dan_file && $r->duong_dan_file !== '—') {
                if (str_starts_with($r->duong_dan_file, 'http')) {
                    $fileUrl = $r->duong_dan_file;
                } else {
                    $fileUrl = asset('storage/' . $r->duong_dan_file);
                }
            }

            return [
                'week' => (int)$r->tuan_so,
                'title' => $title,
                'status' => $status,
                'file' => $r->ten_file_goc ?: ($r->duong_dan_file ? basename($r->duong_dan_file) : '—'),
                'fileUrl' => $fileUrl,
                'note' => $note,
                'teacherComment' => $comment ? $comment->noi_dung : null,
                'updated' => $updatedAt
            ];
        });

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $formatted
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

        $noiDungStr = $title . "\n" . $note;

        // Giữ lại file cũ nếu lần nộp này không đính kèm file mới
        $existing = BaoCaoTienDo::where([
            'sinh_vien_id' => $sinhVien->sinh_vien_id,
            'dot_id' => $activePeriod->dot_id,
            'tuan_so' => $week,
            'loai_bao_cao' => 'THUC_TAP'
        ])->first();

        // Chặn nộp lại nếu tuần này đã được giảng viên duyệt Đạt, tránh sửa bài sau khi đã được chấm
        if ($existing) {
            $existingComment = DB::table('nhanxetbaocao')->where('bao_cao_id', $existing->bao_cao_id)->first();
            if ($existingComment && $existingComment->danh_gia === 'DAT') {
                return response()->json([
                    'success' => false,
                    'message' => "Nhật ký tuần {$week} đã được giảng viên duyệt Đạt. Bạn không thể nộp lại tuần này nữa."
                ], 400);
            }
        }

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

        $fileUrl = null;
        if ($report->duong_dan_file) {
            if (str_starts_with($report->duong_dan_file, 'http')) {
                $fileUrl = $report->duong_dan_file;
            } else {
                $fileUrl = asset('storage/' . $report->duong_dan_file);
            }
        }

        // Trả về đối tượng vừa nộp theo định dạng khớp Frontend
        return response()->json([
            'code' => 200,
            'message' => 'Nộp báo cáo thực tập thành công!',
            'results' => [
                'object' => [
                    'week' => (int)$report->tuan_so,
                    'title' => $title,
                    'status' => 'Chờ duyệt',
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

        // Tìm nhóm ĐATN đã được duyệt của sinh viên (ưu tiên nhóm thực tế thay vì suy luận đợt qua lớp,
        // vì một lớp có thể liên kết nhiều đợt ĐATN cùng lúc)
        $nhom = DB::table('nhomsvda')
            ->join('thanhviennhom', 'nhomsvda.nhom_id', '=', 'thanhviennhom.nhom_id')
            ->where('thanhviennhom.sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('nhomsvda.trang_thai_duyet', 'DA_DUYET')
            ->orderBy('nhomsvda.dot_id', 'desc')
            ->select('nhomsvda.*')
            ->first();

        if (!$nhom) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'objects' => []
                ]
            ]);
        }

        // Lấy danh sách ID của tất cả thành viên trong nhóm
        $memberIds = DB::table('thanhviennhom')
            ->where('nhom_id', $nhom->nhom_id)
            ->pluck('sinh_vien_id')
            ->all();

        $reports = BaoCaoTienDo::whereIn('sinh_vien_id', $memberIds)
            ->where('dot_id', $nhom->dot_id)
            ->where('loai_bao_cao', 'DO_AN')
            ->orderBy('thoi_gian_nop', 'desc')
            ->get();

        $formatted = $reports->map(function ($r) {
            // Tách name, note từ cột noi_dung
            $noiDung = $r->noi_dung ?? '';
            $parts = explode("\n", $noiDung, 2);
            $name = $parts[0] ?? 'Chưa đặt tên';
            $note = $parts[1] ?? '';

            // Định dạng ngày cập nhật
            $updatedAt = $r->thoi_gian_nop ? Carbon::parse($r->thoi_gian_nop)->format('d/m/Y') : '—';

            // Lấy nhận xét từ GV để xác định trạng thái duyệt
            $comment = DB::table('nhanxetbaocao')->where('bao_cao_id', $r->bao_cao_id)->first();

            // Map trạng thái sang chuẩn Frontend mong đợi ('Đã duyệt' | 'Đang chấm điểm' | 'Nháp')
            $status = 'Đang chấm điểm';
            if ($comment) {
                if ($comment->danh_gia === 'DAT') {
                    $status = 'Đã duyệt';
                } else if ($comment->danh_gia === 'CHUA_DAT') {
                    $status = 'Bị từ chối';
                }
            }

            $fileUrl = null;
            if ($r->duong_dan_file && $r->duong_dan_file !== '—') {
                if (str_starts_with($r->duong_dan_file, 'http')) {
                    $fileUrl = $r->duong_dan_file;
                } else {
                    $fileUrl = asset('storage/' . $r->duong_dan_file);
                }
            }

            return [
                'week' => (int)$r->tuan_so,
                'name' => $name,
                'status' => $status,
                'file' => $r->ten_file_goc ?: ($r->duong_dan_file ? basename($r->duong_dan_file) : '—'),
                'fileUrl' => $fileUrl,
                'note' => $note,
                'teacherComment' => $comment ? $comment->noi_dung : null,
                'updated' => $updatedAt
            ];
        });

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $formatted
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

        // Tìm nhóm ĐATN đã được duyệt của sinh viên (ưu tiên nhóm thực tế thay vì suy luận đợt qua lớp,
        // vì một lớp có thể liên kết nhiều đợt ĐATN cùng lúc)
        $nhom = DB::table('nhomsvda')
            ->join('thanhviennhom', 'nhomsvda.nhom_id', '=', 'thanhviennhom.nhom_id')
            ->where('thanhviennhom.sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('nhomsvda.trang_thai_duyet', 'DA_DUYET')
            ->orderBy('nhomsvda.dot_id', 'desc')
            ->select('nhomsvda.*')
            ->first();

        if (!$nhom) {
            return response()->json([
                'success' => false,
                'message' => 'Đề tài tốt nghiệp của bạn chưa được duyệt hoặc đã bị từ chối. Bạn không thể thực hiện nộp báo cáo tiến độ!'
            ], 403);
        }

        $noiDungStr = $name . "\n" . $note;

        // Lấy danh sách ID của tất cả thành viên trong nhóm để kiểm tra/nộp chung báo cáo nhóm
        $memberIds = DB::table('thanhviennhom')
            ->where('nhom_id', $nhom->nhom_id)
            ->pluck('sinh_vien_id')
            ->all();

        // Tìm xem tuần này đã có báo cáo của bất kỳ thành viên nào trong nhóm chưa (giống cơ chế bên TTTN)
        $report = BaoCaoTienDo::whereIn('sinh_vien_id', $memberIds)
            ->where('dot_id', $nhom->dot_id)
            ->where('tuan_so', $week)
            ->where('loai_bao_cao', 'DO_AN')
            ->first();

        // Chặn nộp lại nếu bản thảo này đã được giảng viên duyệt Đạt, tránh sửa bài sau khi đã được chấm
        if ($report) {
            $existingComment = DB::table('nhanxetbaocao')->where('bao_cao_id', $report->bao_cao_id)->first();
            if ($existingComment && $existingComment->danh_gia === 'DAT') {
                return response()->json([
                    'success' => false,
                    'message' => "Bản thảo tuần {$week} đã được giảng viên duyệt Đạt. Bạn không thể nộp lại tuần này nữa."
                ], 400);
            }
        }

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

        $fileUrl = null;
        if ($report->duong_dan_file) {
            if (str_starts_with($report->duong_dan_file, 'http')) {
                $fileUrl = $report->duong_dan_file;
            } else {
                $fileUrl = asset('storage/' . $report->duong_dan_file);
            }
        }

        return response()->json([
            'code' => 200,
            'message' => 'Nộp bản thảo đồ án thành công!',
            'results' => [
                'object' => [
                    'week' => $week,
                    'name' => $name,
                    'status' => 'Đang chấm điểm',
                    'file' => $report->ten_file_goc ?: ($report->duong_dan_file ? basename($report->duong_dan_file) : '—'),
                    'fileUrl' => $fileUrl,
                    'note' => $note,
                    'teacherComment' => null,
                    'updated' => Carbon::parse($report->thoi_gian_nop)->format('d/m/Y')
                ]
            ]
        ]);
    }
}
