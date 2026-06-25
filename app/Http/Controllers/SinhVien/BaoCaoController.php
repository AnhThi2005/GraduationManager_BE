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

            // Map trạng thái sang chuẩn Frontend mong đợi
            $status = 'Nháp';
            if ($r->trang_thai === 'DA_DUYET') {
                $status = 'Đã duyệt';
            } else if ($r->trang_thai === 'CHO_DUYET') {
                $status = 'Chờ duyệt';
            } else if ($r->trang_thai === 'TU_CHOI') {
                $status = 'Bị từ chối';
            }

            return [
                'week' => (int)$r->tuan_so,
                'title' => $title,
                'status' => $status,
                'file' => $r->duong_dan_file ?? '—',
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
            'file' => 'nullable|string'
        ]);

        $week = (int)$request->input('week');
        $title = trim($request->input('title'));
        $note = trim($request->input('note'));
        $file = $request->input('file');

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
            'duong_dan_file' => $file ?? "week{$week}.pdf",
            'trang_thai' => 'CHO_DUYET',
            'thoi_gian_nop' => Carbon::now()->toDateTimeString()
        ]);

        // Trả về đối tượng vừa nộp theo định dạng khớp Frontend
        return response()->json([
            'code' => 200,
            'message' => 'Nộp báo cáo thực tập thành công!',
            'results' => [
                'object' => [
                    'week' => (int)$report->tuan_so,
                    'title' => $title,
                    'status' => 'Chờ duyệt',
                    'file' => $report->duong_dan_file,
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

        $reports = BaoCaoTienDo::where('sinh_vien_id', $sinhVien->sinh_vien_id)
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

            // Map trạng thái sang chuẩn Frontend mong đợi ('Đã duyệt' | 'Đang chấm điểm' | 'Nháp')
            $status = 'Nháp';
            if ($r->trang_thai === 'DA_DUYET') {
                $status = 'Đã duyệt';
            } else if ($r->trang_thai === 'CHO_DUYET') {
                $status = 'Đang chấm điểm';
            } else if ($r->trang_thai === 'TU_CHOI') {
                $status = 'Bị từ chối';
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

        $noiDungStr = $name . "\n" . $repo . "\n" . $note;

        // Tìm xem đã có báo cáo trùng tên bản thảo chưa (so sánh dòng đầu tiên của cột noi_dung)
        $report = BaoCaoTienDo::where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->where('dot_id', $activePeriod->dot_id)
            ->where('loai_bao_cao', 'DO_AN')
            ->where('noi_dung', 'like', $name . "\n%")
            ->first();

        if (!$report) {
            // Đếm số báo cáo ĐATN hiện có để tính số tuần tuần tự
            $count = BaoCaoTienDo::where('sinh_vien_id', $sinhVien->sinh_vien_id)
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
                'trang_thai' => 'CHO_DUYET',
                'thoi_gian_nop' => Carbon::now()->toDateTimeString()
            ]);
        } else {
            // Cập nhật bản ghi cũ
            $report->update([
                'noi_dung' => $noiDungStr,
                'duong_dan_file' => $file ?? $report->duong_dan_file,
                'trang_thai' => 'CHO_DUYET',
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
