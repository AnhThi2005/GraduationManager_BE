<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\DeTai;
use App\Models\Nhom;
use App\Models\SinhVien;
use App\Models\Dot;
use App\Models\LoiMoiNhom;
use Illuminate\Support\Facades\DB;

class DeTaiController extends Controller
{
    /**
     * Xem hồ sơ đăng ký đề tài/nhóm ĐATN của sinh viên
     */
    public function xemDangKyCuaToi(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập.'
            ], 401);
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
                    'object' => null
                ]
            ]);
        }

        // Tìm nhóm mà sinh viên là thành viên
        $nhom = Nhom::with(['deTai', 'dot'])
            ->where('dot_id', $activePeriod->dot_id)
            ->whereHas('members', function($q) use ($sinhVien) {
                $q->where('sinhvien.sinh_vien_id', $sinhVien->sinh_vien_id);
            })->first();

        if (!$nhom) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => null
                ]
            ]);
        }

        $status = 'pending';
        if ($nhom->trang_thai_duyet === 'DA_DUYET') {
            $status = 'accepted';
        } else if ($nhom->trang_thai_duyet === 'TU_CHOI') {
            $status = 'rejected';
        }

        $note = $nhom->nhan_xet_phan_bien ?? 'Đang chờ giảng viên phê duyệt hồ sơ đề tài.';

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'topicId' => (string)$nhom->de_tai_id,
                    'topicTitle' => $nhom->deTai ? $nhom->deTai->ten_de_tai : 'Đề tài tự do',
                    'groupName' => 'Nhóm số #' . $nhom->nhom_id,
                    'batch' => $nhom->dot ? $nhom->dot->ten_dot : $activePeriod->ten_dot,
                    'submittedAt' => '24/06/2026 21:00', // Mocked date
                    'status' => $status,
                    'note' => $note
                ]
            ]
        ]);
    }

    /**
     * Đăng ký đề tài ĐATN và tự động tạo nhóm mới
     */
    public function dangKyDeTai(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập.'
            ], 401);
        }

        $request->validate([
            'topicId' => 'required|integer'
        ]);

        $topicId = $request->input('topicId');
        $deTai = DeTai::find($topicId);

        if (!$deTai) {
            return response()->json([
                'success' => false,
                'message' => 'Đề tài không tồn tại.'
            ], 404);
        }

        $dotId = $deTai->dot_id;

        // Tìm nhóm mà sinh viên đang tham gia trong đợt này
        $nhom = Nhom::where('dot_id', $dotId)
            ->whereHas('members', function($q) use ($sinhVien) {
                $q->where('sinhvien.sinh_vien_id', $sinhVien->sinh_vien_id);
            })->first();

        // Yêu cầu phải có nhóm trước mới được đăng ký đề tài
        if (!$nhom) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn phải tạo nhóm ĐATN trước khi đăng ký đề tài!'
            ], 400);
        }

        // Kiểm tra xem sinh viên hiện tại có phải là trưởng nhóm hay không. Chỉ trưởng nhóm mới được đăng ký đề tài.
        $pivot = DB::table('thanhviennhom')
            ->where('nhom_id', $nhom->nhom_id)
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->first();

        if (!$pivot || $pivot->la_truong_nhom != 1) {
            return response()->json([
                'success' => false,
                'message' => 'Chỉ trưởng nhóm mới có quyền đăng ký đề tài cho nhóm.'
            ], 400);
        }

        // Nếu đề tài đã được duyệt, không cho phép thay đổi
        if ($nhom->trang_thai_duyet === 'DA_DUYET') {
            return response()->json([
                'success' => false,
                'message' => 'Đề tài của nhóm đã được phê duyệt, bạn không thể thay đổi đề tài.'
            ], 400);
        }

        // Cập nhật đề tài cho nhóm hiện tại
        DB::beginTransaction();
        try {
            $nhom->update([
                'de_tai_id' => $deTai->de_tai_id,
                'trang_thai_duyet' => 'CHO_DUYET'
            ]);

            // Lưu thông tin đăng ký vào bảng dangkydetai
            DB::table('dangkydetai')->insert([
                'nhom_id' => $nhom->nhom_id,
                'de_tai_id' => $deTai->de_tai_id,
                'trang_thai_duyet' => 'CHO_DUYET',
                'ngay_dang_ky' => date('Y-m-d H:i:s'),
                'ly_do_tu_choi' => null
            ]);

            // Broadcast real-time event when a student registers a topic
            \App\Services\RealtimeService::broadcast('slot_updated', [
                'type' => 'student_registered_topic',
                'topicId' => $topicId,
                'nhomId' => $nhom->nhom_id
            ]);

            \App\Services\RealtimeService::broadcast('notification', [
                'title' => 'Đăng ký đề tài mới',
                'message' => 'Sinh viên ' . $sinhVien->ho_ten . ' vừa đăng ký đề tài: ' . $deTai->ten_de_tai,
                'type' => 'student_registered_topic',
                'payload' => [
                    'topicId' => (string)$deTai->de_tai_id,
                    'topicTitle' => $deTai->ten_de_tai,
                    'groupName' => 'Nhóm số #' . $nhom->nhom_id,
                    'studentName' => $sinhVien->ho_ten,
                ]
            ]);

            DB::commit();

            return response()->json([
                'code' => 200,
                'message' => 'Đăng ký đề tài ĐATN thành công!',
                'results' => [
                    'object' => [
                        'topicId' => (string)$deTai->de_tai_id,
                        'topicTitle' => $deTai->ten_de_tai,
                        'groupName' => 'Nhóm số #' . $nhom->nhom_id,
                        'batch' => $deTai->dot ? $deTai->dot->ten_dot : 'Đợt hiện tại',
                        'submittedAt' => date('d/m/Y H:i'),
                        'status' => 'pending',
                        'note' => 'Đã gửi yêu cầu đăng ký đề tài lên giảng viên.'
                    ]
                ]
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi đăng ký đề tài: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Hủy đăng ký đề tài/nhóm ĐATN
     */
    public function huyDangKy(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng nhập.'
            ], 401);
        }

        $lopId = $sinhVien->lop_id;
        $activePeriod = Dot::where('loai_dot', 'DATN')
            ->whereHas('lops', function($q) use ($lopId) {
                $q->where('lop.lop_id', $lopId);
            })->orderBy('dot_id', 'desc')->first();

        if (!$activePeriod) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt ĐATN hiện tại.'
            ], 400);
        }

        // Tìm nhóm
        $nhom = Nhom::where('dot_id', $activePeriod->dot_id)
            ->whereHas('members', function($q) use ($sinhVien) {
                $q->where('sinhvien.sinh_vien_id', $sinhVien->sinh_vien_id);
            })->first();

        if (!$nhom) {
            return response()->json([
                'success' => false,
                'message' => 'Bạn chưa đăng ký đề tài nào.'
            ], 400);
        }

        if ($nhom->trang_thai_duyet === 'DA_DUYET') {
            return response()->json([
                'success' => false,
                'message' => 'Đề tài đã được phê duyệt bởi giảng viên, bạn không thể tự ý hủy đăng ký.'
            ], 400);
        }

        // Kiểm tra xem sinh viên có phải trưởng nhóm không
        $pivot = DB::table('thanhviennhom')
            ->where('nhom_id', $nhom->nhom_id)
            ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
            ->first();

        DB::beginTransaction();
        try {
            if ($pivot && $pivot->la_truong_nhom == 1) {
                // Xóa đăng ký đề tài tương ứng
                DB::table('dangkydetai')->where('nhom_id', $nhom->nhom_id)->delete();
                // Xóa tất cả thành viên và xóa nhóm
                DB::table('thanhviennhom')->where('nhom_id', $nhom->nhom_id)->delete();
                $nhom->delete();
            } else {
                // Chỉ xóa liên kết của chính sinh viên này
                DB::table('thanhviennhom')
                    ->where('nhom_id', $nhom->nhom_id)
                    ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
                    ->delete();
            }

            \App\Services\RealtimeService::broadcast('slot_updated', [
                'type' => 'student_cancelled_registration',
                'nhomId' => $nhom->nhom_id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Hủy đăng ký đề tài tốt nghiệp thành công!'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi hủy đăng ký: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lấy danh sách lời mời nhóm đã gửi (từ nhóm của tôi)
     */
    public function xemLoiMoiDaGui(Request $request)
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

        // Tìm nhóm của sinh viên này
        $nhom = Nhom::where('dot_id', $activePeriod->dot_id)
            ->whereHas('members', function($q) use ($sinhVien) {
                $q->where('sinhvien.sinh_vien_id', $sinhVien->sinh_vien_id);
            })->first();

        if (!$nhom) {
            return response()->json([
                'code' => 200,
                'results' => [
                    'objects' => []
                ]
            ]);
        }

        // Lấy tất cả lời mời gửi đi của nhóm này
        $loiMois = LoiMoiNhom::with('sinhVienDuocMoi')
            ->where('nhom_id', $nhom->nhom_id)
            ->get();

        $formatted = $loiMois->map(function ($lm) {
            $status = 'pending';
            if ($lm->trang_thai_xac_nhan === 'DA_CHAP_NHAN') {
                $status = 'accepted';
            } else if ($lm->trang_thai_xac_nhan === 'TU_CHOI') {
                $status = 'rejected';
            }

            return [
                'id' => $lm->sinhVienDuocMoi ? $lm->sinhVienDuocMoi->ma_so_sinh_vien : '',
                'status' => $status
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
     * Gửi lời mời gia nhập nhóm đồ án tốt nghiệp
     */
    public function guiLoiMoiNhom(Request $request)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        $request->validate([
            'studentCode' => 'required|string',
            'topicId' => 'nullable'
        ]);

        $studentCode = $request->input('studentCode');
        $topicId = $request->input('topicId');
        // convert string/number topicId to clean type
        if ($topicId !== null) {
            $topicId = (int)$topicId;
            if ($topicId <= 0 || !\App\Models\DeTai::where('de_tai_id', $topicId)->exists()) {
                $topicId = null;
            }
        }

        // Tìm đợt ĐATN đang diễn ra của sinh viên
        $lopId = $sinhVien->lop_id;
        $activePeriod = Dot::where('loai_dot', 'DATN')
            ->whereHas('lops', function($q) use ($lopId) {
                $q->where('lop.lop_id', $lopId);
            })->orderBy('dot_id', 'desc')->first();

        if (!$activePeriod) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy đợt ĐATN hiện tại.'], 400);
        }

        // Tìm nhóm của sinh viên hiện tại trong đợt này
        $nhom = Nhom::where('dot_id', $activePeriod->dot_id)
            ->whereHas('members', function($q) use ($sinhVien) {
                $q->where('sinhvien.sinh_vien_id', $sinhVien->sinh_vien_id);
            })->first();

        DB::beginTransaction();
        try {
            if ($nhom) {
                if ($nhom->trang_thai_duyet === 'DA_DUYET') {
                    return response()->json(['success' => false, 'message' => 'Nhóm đề tài của bạn đã được duyệt. Không thể mời thêm thành viên!'], 400);
                }
                if ($nhom->trang_thai_duyet === 'TU_CHOI') {
                    return response()->json(['success' => false, 'message' => 'Nhóm đề tài của bạn đã bị từ chối. Vui lòng hủy đăng ký đề tài này để bắt đầu lại.'], 400);
                }

                // Kiểm tra xem sinh viên hiện tại có phải là trưởng nhóm hay không. Chỉ trưởng nhóm mới được mời thành viên.
                $pivot = DB::table('thanhviennhom')
                    ->where('nhom_id', $nhom->nhom_id)
                    ->where('sinh_vien_id', $sinhVien->sinh_vien_id)
                    ->first();

                if (!$pivot || $pivot->la_truong_nhom != 1) {
                    return response()->json(['success' => false, 'message' => 'Chỉ trưởng nhóm mới có quyền gửi lời mời thành viên.'], 400);
                }

                // Check giới hạn 2 thành viên trong nhóm (bao gồm cả thành viên hiện tại và các lời mời đang chờ)
                $memberCount = DB::table('thanhviennhom')->where('nhom_id', $nhom->nhom_id)->count();
                $invitedCount = LoiMoiNhom::where('nhom_id', $nhom->nhom_id)->where('trang_thai_xac_nhan', 'CHO_XAC_NHAN')->count();
                if ($memberCount + $invitedCount >= 2) {
                    return response()->json(['success' => false, 'message' => 'Nhóm đã đạt hoặc vượt quá số lượng thành viên tối đa (tối đa 2 sinh viên).'], 400);
                }
            } else {
                // Nếu sinh viên chưa có nhóm, tự động tạo nhóm mới với vai trò trưởng nhóm
                $nhom = Nhom::create([
                    'de_tai_id' => $topicId, // Có thể null nếu sinh viên chưa chọn đề tài mà chỉ tạo nhóm trước
                    'dot_id' => $activePeriod->dot_id,
                    'trang_thai_nhom' => 'HOAT_DONG',
                    'trang_thai_duyet' => 'CHO_DUYET'
                ]);

                // Thêm sinh viên hiện tại làm trưởng nhóm
                DB::table('thanhviennhom')->insert([
                    'nhom_id' => $nhom->nhom_id,
                    'sinh_vien_id' => $sinhVien->sinh_vien_id,
                    'la_truong_nhom' => 1,
                    'dieu_kien_lam_do_an' => 'DAT'
                ]);

                // Nếu có chọn đề tài, lưu đăng ký vào bảng dangkydetai
                if ($topicId) {
                    DB::table('dangkydetai')->insert([
                        'nhom_id' => $nhom->nhom_id,
                        'de_tai_id' => $topicId,
                        'trang_thai_duyet' => 'CHO_DUYET',
                        'ngay_dang_ky' => date('Y-m-d H:i:s'),
                        'ly_do_tu_choi' => null
                    ]);
                }
            }

            // Tìm sinh viên cần mời theo mã số sinh viên
            $targetStudent = SinhVien::where('ma_so_sinh_vien', $studentCode)->first();
            if (!$targetStudent) {
                return response()->json(['success' => false, 'message' => 'Không tìm thấy sinh viên có mã số ' . $studentCode], 404);
            }

            // Kiểm tra xem sinh viên được mời có đang hoạt động không
            if (!$targetStudent->dang_hoat_dong) {
                return response()->json(['success' => false, 'message' => 'Tài khoản của sinh viên được mời đã bị khóa.'], 400);
            }

            if ($targetStudent->sinh_vien_id === $sinhVien->sinh_vien_id) {
                return response()->json(['success' => false, 'message' => 'Bạn không thể tự mời chính mình.'], 400);
            }

            // Kiểm tra xem sinh viên được mời đã có nhóm trong đợt này chưa
            $targetHasGroup = Nhom::where('dot_id', $activePeriod->dot_id)
                ->whereHas('members', function($q) use ($targetStudent) {
                    $q->where('sinhvien.sinh_vien_id', $targetStudent->sinh_vien_id);
                })->exists();

            if ($targetHasGroup) {
                return response()->json(['success' => false, 'message' => 'Sinh viên này đã gia nhập một nhóm khác.'], 400);
            }

            // Kiểm tra xem đã có lời mời trùng lặp chưa
            $existingInvite = LoiMoiNhom::where('nhom_id', $nhom->nhom_id)
                ->where('sinh_vien_duoc_moi_id', $targetStudent->sinh_vien_id)
                ->where('trang_thai_xac_nhan', 'CHO_XAC_NHAN')
                ->first();

            if ($existingInvite) {
                return response()->json(['success' => false, 'message' => 'Bạn đã gửi lời mời cho sinh viên này rồi và đang chờ phản hồi.'], 400);
            }

            // Tạo lời mời mới
            LoiMoiNhom::create([
                'nhom_id' => $nhom->nhom_id,
                'sinh_vien_duoc_moi_id' => $targetStudent->sinh_vien_id,
                'trang_thai_xac_nhan' => 'CHO_XAC_NHAN',
                'ngay_tao' => date('Y-m-d H:i:s')
            ]);

            DB::commit();

            return response()->json([
                'code' => 200,
                'message' => 'Gửi lời mời thành công!',
                'results' => [
                    'object' => [
                        'id' => $targetStudent->ma_so_sinh_vien,
                        'status' => 'pending'
                    ]
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi gửi lời mời: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Lấy danh sách lời mời gia nhập nhóm nhận được
     */
    public function xemLoiMoiNhanDuoc(Request $request)
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

        // Lấy tất cả lời mời chờ hoặc đã xử lý gửi tới sinh viên này trong đợt hiện tại
        $loiMois = LoiMoiNhom::with(['nhom.deTai', 'nhom.members'])
            ->where('sinh_vien_duoc_moi_id', $sinhVien->sinh_vien_id)
            ->whereHas('nhom', function($q) use ($activePeriod) {
                $q->where('dot_id', $activePeriod->dot_id);
            })->get();

        $formatted = $loiMois->map(function ($lm) {
            $nhom = $lm->nhom;
            // Tìm người gửi (trưởng nhóm)
            $leader = $nhom ? $nhom->members->first(function($m) {
                return $m->pivot->la_truong_nhom == 1;
            }) : null;

            $senderName = $leader ? $leader->ho_ten : 'Trưởng nhóm';
            $topicTitle = ($nhom && $nhom->deTai) ? $nhom->deTai->ten_de_tai : 'Nhóm chưa chọn đề tài';

            $status = 'pending';
            if ($lm->trang_thai_xac_nhan === 'DA_CHAP_NHAN') {
                $status = 'accepted';
            } else if ($lm->trang_thai_xac_nhan === 'TU_CHOI') {
                $status = 'rejected';
            }

            return [
                'id' => (string)$lm->loi_moi_id, // Sử dụng loi_moi_id làm ID của lời mời
                'from' => $senderName,
                'topic' => $topicTitle,
                'status' => $status
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
     * Chấp nhận lời mời gia nhập nhóm
     */
    public function chapNhanLoiMoi(Request $request, $id)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        $loiMoi = LoiMoiNhom::find($id);
        if (!$loiMoi) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy lời mời này.'], 404);
        }

        if ($loiMoi->sinh_vien_duoc_moi_id !== $sinhVien->sinh_vien_id) {
            return response()->json(['success' => false, 'message' => 'Bạn không được phép xử lý lời mời này.'], 403);
        }

        if ($loiMoi->trang_thai_xac_nhan !== 'CHO_XAC_NHAN') {
            return response()->json(['success' => false, 'message' => 'Lời mời này đã được xử lý trước đó.'], 400);
        }

        $nhom = Nhom::find($loiMoi->nhom_id);
        if (!$nhom) {
            return response()->json(['success' => false, 'message' => 'Nhóm gửi lời mời không còn tồn tại.'], 400);
        }

        if ($nhom->trang_thai_duyet === 'DA_DUYET') {
            return response()->json(['success' => false, 'message' => 'Nhóm này đã được duyệt đề tài tốt nghiệp, không thể tham gia thêm thành viên.'], 400);
        }
        if ($nhom->trang_thai_duyet === 'TU_CHOI') {
            return response()->json(['success' => false, 'message' => 'Nhóm này đã bị từ chối đề tài tốt nghiệp, không thể tham gia.'], 400);
        }

        // Kiểm tra số lượng thành viên hiện tại của nhóm
        $memberCount = DB::table('thanhviennhom')->where('nhom_id', $nhom->nhom_id)->count();
        if ($memberCount >= 2) {
            return response()->json(['success' => false, 'message' => 'Nhóm đã đạt số lượng thành viên tối đa (tối đa 2 sinh viên).'], 400);
        }

        // Kiểm tra xem sinh viên hiện tại đã gia nhập nhóm nào khác trong đợt này chưa
        $existingGroup = Nhom::where('dot_id', $nhom->dot_id)
            ->whereHas('members', function($q) use ($sinhVien) {
                $q->where('sinhvien.sinh_vien_id', $sinhVien->sinh_vien_id);
            })->first();

        if ($existingGroup) {
            return response()->json(['success' => false, 'message' => 'Bạn đã tham gia một nhóm khác trong đợt tốt nghiệp này rồi.'], 400);
        }

        // Thực hiện thêm sinh viên vào thành viên nhóm và đổi trạng thái lời mời
        DB::beginTransaction();
        try {
            // Cập nhật lời mời hiện tại
            $loiMoi->update(['trang_thai_xac_nhan' => 'DA_CHAP_NHAN']);

            // Chèn vào bảng thành viên nhóm
            DB::table('thanhviennhom')->insert([
                'nhom_id' => $nhom->nhom_id,
                'sinh_vien_id' => $sinhVien->sinh_vien_id,
                'la_truong_nhom' => 0,
                'dieu_kien_lam_do_an' => 'DAT'
            ]);

            // Từ chối tất cả lời mời chờ khác của sinh viên này trong đợt hiện tại
            LoiMoiNhom::where('sinh_vien_duoc_moi_id', $sinhVien->sinh_vien_id)
                ->where('trang_thai_xac_nhan', 'CHO_XAC_NHAN')
                ->update(['trang_thai_xac_nhan' => 'TU_CHOI']);

            \App\Services\RealtimeService::broadcast('slot_updated', [
                'type' => 'group_member_joined',
                'nhomId' => $nhom->nhom_id
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Chấp nhận lời mời gia nhập nhóm thành công!'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi xử lý chấp nhận lời mời: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Từ chối lời mời gia nhập nhóm
     */
    public function tuChoiLoiMoi(Request $request, $id)
    {
        $sinhVien = $request->user();
        if (!$sinhVien) {
            return response()->json(['success' => false, 'message' => 'Bạn chưa đăng nhập.'], 401);
        }

        $loiMoi = LoiMoiNhom::find($id);
        if (!$loiMoi) {
            return response()->json(['success' => false, 'message' => 'Không tìm thấy lời mời này.'], 404);
        }

        if ($loiMoi->sinh_vien_duoc_moi_id !== $sinhVien->sinh_vien_id) {
            return response()->json(['success' => false, 'message' => 'Bạn không được phép xử lý lời mời này.'], 403);
        }

        if ($loiMoi->trang_thai_xac_nhan !== 'CHO_XAC_NHAN') {
            return response()->json(['success' => false, 'message' => 'Lời mời này đã được xử lý trước đó.'], 400);
        }

        try {
            $loiMoi->update(['trang_thai_xac_nhan' => 'TU_CHOI']);

            return response()->json([
                'success' => true,
                'message' => 'Từ chối lời mời gia nhập nhóm thành công!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi hệ thống khi xử lý từ chối: ' . $e->getMessage()
            ], 500);
        }
    }
}
