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
            ->orderBy('tuan_so', 'asc')
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
                } else if ($comment->danh_gia === 'KHONG_DAT') {
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
                'file' => $r->duong_dan_file ? basename($r->duong_dan_file) : '—',
                'fileUrl' => $fileUrl,
                'note' => $note,
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
            'file' => 'nullable'
        ]);

        $week = (int)$request->input('week');
        $title = trim($request->input('title'));
        $note = trim($request->input('note'));

        $filePath = null;
        if ($request->hasFile('file')) {
            $uploadedFile = $request->file('file');
            $filePath = $uploadedFile->store('reports', 'public');
        } else {
            $filePath = $request->input('file');
        }

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

        // Lưu hoặc cập nhật báo cáo tuần của sinh viên trong đợt này
        $report = BaoCaoTienDo::updateOrCreate([
            'sinh_vien_id' => $sinhVien->sinh_vien_id,
            'dot_id' => $activePeriod->dot_id,
            'tuan_so' => $week,
            'loai_bao_cao' => 'THUC_TAP'
        ], [
            'noi_dung' => $noiDungStr,
            'duong_dan_file' => $filePath ?? "week{$week}.pdf",
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
                    'file' => basename($report->duong_dan_file),
                    'fileUrl' => $fileUrl,
                    'note' => $note,
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

        // Lấy đợt ĐATN đang diễn ra của sinh viên
        $lopId = $sinhVien->lop_id;
        $activePeriod = Dot::where('loai_dot', 'DATN')
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

        // Kiểm tra xem sinh viên đã được duyệt nhóm đề tài chưa
        $nhom = DB::table('nhomsvda')
            ->join('thanhviennhom', 'nhomsvda.nhom_id', '=', 'thanhviennhom.nhom_id')
            ->where('nhomsvda.dot_id', $activePeriod->dot_id)
            ->where('thanhviennhom.sinh_vien_id', $sinhVien->sinh_vien_id)
            ->first();

        if (!$nhom || $nhom->trang_thai_duyet !== 'DA_DUYET') {
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
            ->where('dot_id', $activePeriod->dot_id)
            ->where('loai_bao_cao', 'DO_AN')
            ->orderBy('tuan_so', 'asc')
            ->get();

        $formatted = $reports->map(function ($r) {
            // Tách name, repo, note từ cột noi_dung
            $noiDung = $r->noi_dung ?? '';
            $parts = explode("\n", $noiDung, 3);
            $name = $parts[0] ?? 'Chưa đặt tên';
            $repo = $parts[1] ?? '—';
            $note = $parts[2] ?? '';

            // Định dạng ngày cập nhật
            $updatedAt = $r->thoi_gian_nop ? Carbon::parse($r->thoi_gian_nop)->format('d/m/Y') : '—';

            // Lấy nhận xét từ GV để xác định trạng thái duyệt
            $comment = DB::table('nhanxetbaocao')->where('bao_cao_id', $r->bao_cao_id)->first();

            // Map trạng thái sang chuẩn Frontend mong đợi ('Đã duyệt' | 'Đang chấm điểm' | 'Nháp')
            $status = 'Đang chấm điểm';
            if ($comment) {
                if ($comment->danh_gia === 'DAT') {
                    $status = 'Đã duyệt';
                } else if ($comment->danh_gia === 'KHONG_DAT') {
                    $status = 'Bị từ chối';
                }
            }

            return [
                'name' => $name,
                'status' => $status,
                'file' => $r->duong_dan_file ?? '—',
                'repo' => $repo,
                'note' => $note,
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
            'name' => 'required|string|max:255',
            'note' => 'required|string',
            'repo' => 'nullable|string',
            'file' => 'nullable|string'
        ]);

        $name = trim($request->input('name'));
        $note = trim($request->input('note'));
        $repo = trim($request->input('repo')) ?: '—';
        $file = $request->input('file');

        // Lấy đợt ĐATN đang diễn ra của sinh viên
        $lopId = $sinhVien->lop_id;
        $activePeriod = Dot::where('loai_dot', 'DATN')
            ->whereHas('lops', function($q) use ($lopId) {
                $q->where('lop.lop_id', $lopId);
            })->orderBy('dot_id', 'desc')->first();

        if (!$activePeriod) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đợt tốt nghiệp hiện tại.'], 400);
        }

        // Kiểm tra xem sinh viên đã có nhóm và đề tài được duyệt chưa
        $nhom = DB::table('nhomsvda')
            ->join('thanhviennhom', 'nhomsvda.nhom_id', '=', 'thanhviennhom.nhom_id')
            ->where('nhomsvda.dot_id', $activePeriod->dot_id)
            ->where('thanhviennhom.sinh_vien_id', $sinhVien->sinh_vien_id)
            ->first();

        if (!$nhom || $nhom->trang_thai_duyet !== 'DA_DUYET') {
            return response()->json([
                'success' => false,
                'message' => 'Đề tài tốt nghiệp của bạn chưa được duyệt hoặc đã bị từ chối. Bạn không thể thực hiện nộp báo cáo tiến độ!'
            ], 403);
        }

        $noiDungStr = $name . "\n" . $repo . "\n" . $note;

        // Lấy danh sách ID của tất cả thành viên trong nhóm để kiểm tra/nộp chung báo cáo nhóm
        $memberIds = DB::table('thanhviennhom')
            ->where('nhom_id', $nhom->nhom_id)
            ->pluck('sinh_vien_id')
            ->all();

        // Tìm xem đã có báo cáo trùng tên bản thảo chưa (so sánh dòng đầu tiên của cột noi_dung) của bất kỳ thành viên nào trong nhóm
        $report = BaoCaoTienDo::whereIn('sinh_vien_id', $memberIds)
            ->where('dot_id', $activePeriod->dot_id)
            ->where('loai_bao_cao', 'DO_AN')
            ->where('noi_dung', 'like', $name . "\n%")
            ->first();

        if (!$report) {
            // Đếm số báo cáo ĐATN hiện có của nhóm để tính số tuần tuần tự
            $count = BaoCaoTienDo::whereIn('sinh_vien_id', $memberIds)
                ->where('dot_id', $activePeriod->dot_id)
                ->where('loai_bao_cao', 'DO_AN')
                ->count();

            $report = BaoCaoTienDo::create([
                'sinh_vien_id' => $sinhVien->sinh_vien_id,
                'dot_id' => $activePeriod->dot_id,
                'tuan_so' => $count + 1,
                'loai_bao_cao' => 'DO_AN',
                'noi_dung' => $noiDungStr,
                'duong_dan_file' => $file ?? 'draft.pdf',
                'trang_thai' => 'DA_NOP',
                'thoi_gian_nop' => Carbon::now()->toDateTimeString()
            ]);
        } else {
            // Cập nhật bản ghi cũ
            $report->update([
                'noi_dung' => $noiDungStr,
                'duong_dan_file' => $file ?? $report->duong_dan_file,
                'trang_thai' => 'DA_NOP',
                'thoi_gian_nop' => Carbon::now()->toDateTimeString()
            ]);
        }

        return response()->json([
            'code' => 200,
            'message' => 'Nộp bản thảo đồ án thành công!',
            'results' => [
                'object' => [
                    'name' => $name,
                    'status' => 'Đang chấm điểm',
                    'file' => $report->duong_dan_file,
                    'repo' => $repo,
                    'note' => $note,
                    'updated' => Carbon::parse($report->thoi_gian_nop)->format('d/m/Y')
                ]
            ]
        ]);
    }
}
