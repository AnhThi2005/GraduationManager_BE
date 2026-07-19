<?php

namespace App\Http\Controllers\SinhVien;

use App\Http\Controllers\Controller;
use App\Models\BaoCaoTienDo;
use App\Models\CongTy;
use App\Models\DangKyThucTap;
use App\Models\DeTai;
use App\Models\Dot;
use App\Models\LoiMoiNhom;
use App\Models\Nhom;
use App\Models\PhanCongHdtt;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TrangChuController extends Controller
{
    public function layThongTinTrangChu(Request $request)
    {
        $sinhVien = $request->user();
        if (! $sinhVien) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy thông tin sinh viên!',
            ], 401);
        }

        $sinhVienId = $sinhVien->sinh_vien_id;
        $lopId = $sinhVien->lop_id;

        // 1. Tìm các đợt học (periods) liên quan đến sinh viên (qua lớp hoặc trực tiếp)
        $dots = Dot::whereHas('lops', function ($query) use ($lopId) {
            $query->where('lop.lop_id', $lopId);
        })->orWhereHas('sinhViens', function ($query) use ($sinhVienId) {
            $query->where('sinhvien.sinh_vien_id', $sinhVienId);
        })->get();

        // Đợt TTTN hiện tại (chọn đợt TTTN đang hoạt động/mới nhất - đồng bộ tiêu chí với ThucTapController::khaiBaoThucTap)
        $dotTttn = $dots->where('loai_dot', 'TTTN')->where('trang_thai', '!=', 'DA_DONG')->sortByDesc('dot_id')->first()
            ?? $dots->where('loai_dot', 'TTTN')->sortByDesc('dot_id')->first();

        // Đợt ĐATN hiện tại (chọn đợt ĐATN đang hoạt động/mới nhất)
        $dotDatn = $dots->where('loai_dot', 'DATN')->where('trang_thai', '!=', 'DA_DONG')->sortByDesc('dot_id')->first()
            ?? $dots->where('loai_dot', 'DATN')->sortByDesc('dot_id')->first();

        // 2. Thông tin Thực tập tốt nghiệp (TTTN)
        $tttnInfo = [
            'hasPeriod' => (bool) $dotTttn,
            'periodName' => $dotTttn ? $dotTttn->ten_dot : null,
            'status' => 'Chưa đăng ký', // Mặc định
            'companyName' => null,
            'mentor' => null, // Người hướng dẫn tại doanh nghiệp (do SV tự khai báo)
            'position' => null, // Vị trí thực tập (do SV tự khai báo)
            'supervisorTeacher' => null, // GVHD do trường phân công (chỉ hiện sau khi admin đã công bố)
            'trangThaiDangKy' => null,
        ];

        if ($dotTttn) {
            $dangKy = DangKyThucTap::where('sinh_vien_id', $sinhVienId)
                ->where('dot_id', $dotTttn->dot_id)
                ->first();

            if ($dangKy) {
                $tttnInfo['trangThaiDangKy'] = $dangKy->trang_thai; // CHO_DUYET, DA_DUYET, TU_CHOI
                if ($dangKy->trang_thai === 'DA_DUYET') {
                    $tttnInfo['status'] = 'Đang thực tập';
                } elseif ($dangKy->trang_thai === 'CHO_DUYET') {
                    $tttnInfo['status'] = 'Chờ phê duyệt';
                } else {
                    $tttnInfo['status'] = 'Bị từ chối';
                }

                $congTy = CongTy::find($dangKy->cong_ty_id);
                $tttnInfo['companyName'] = $congTy ? $congTy->ten_cong_ty : ($dangKy->dia_chi_thuc_tap ?? '');
                $tttnInfo['mentor'] = $dangKy->nguoi_huong_dan;
                $tttnInfo['position'] = $dangKy->vi_tri_cong_viec;
            }

            // GVHD do trường phân công (Phân công hướng dẫn TTTN) - chỉ hiện khi admin đã công bố
            $phanCong = PhanCongHdtt::with('giangVien')
                ->where('sinh_vien_id', $sinhVienId)
                ->where('dot_id', $dotTttn->dot_id)
                ->where('da_cong_bo', true)
                ->first();
            if ($phanCong && $phanCong->giangVien) {
                $tttnInfo['supervisorTeacher'] = $phanCong->giangVien->ho_ten;
            }
        }

        // 3. Thông tin Đồ án tốt nghiệp (ĐATN)
        $datnInfo = [
            'hasPeriod' => (bool) $dotDatn,
            'periodName' => $dotDatn ? $dotDatn->ten_dot : null,
            'status' => 'Chưa tham gia',
            'topicTitle' => null,
            'instructor' => null,
            'groupName' => null,
            'groupId' => null,
        ];

        if ($dotDatn) {
            // Tìm nhóm của sinh viên trong đợt ĐATN này
            $nhom = Nhom::where('dot_id', $dotDatn->dot_id)
                ->whereHas('members', function ($q) use ($sinhVienId) {
                    $q->where('sinhvien.sinh_vien_id', $sinhVienId);
                })->first();

            if ($nhom) {
                $datnInfo['groupId'] = $nhom->nhom_id;
                $datnInfo['groupName'] = 'Nhóm';

                if ($nhom->de_tai_id) {
                    $deTai = DeTai::with('giangVien')->find($nhom->de_tai_id);
                    if ($deTai) {
                        if ($nhom->trang_thai_duyet === 'DA_DUYET') {
                            $datnInfo['status'] = 'Đã đăng ký';
                        } elseif ($nhom->trang_thai_duyet === 'TU_CHOI') {
                            $datnInfo['status'] = 'Bị từ chối';
                        } else {
                            $datnInfo['status'] = 'Chờ duyệt';
                        }
                        $datnInfo['topicTitle'] = $deTai->ten_de_tai;
                        $datnInfo['instructor'] = $deTai->giangVien ? $deTai->giangVien->ho_ten : null;
                    }
                } else {
                    if ($nhom->trang_thai_duyet === 'TU_CHOI') {
                        $datnInfo['status'] = 'Bị từ chối';
                    } else {
                        $datnInfo['status'] = 'Chưa chọn đề tài';
                    }
                }
            }
        }

        // 4. Số lượng báo cáo đã nộp
        // Đếm báo cáo TTTN & ĐATN của sinh viên này
        $tttnReportsCount = $dotTttn ? BaoCaoTienDo::where('sinh_vien_id', $sinhVienId)->where('dot_id', $dotTttn->dot_id)->where('loai_bao_cao', 'THUC_TAP')->count() : 0;
        $datnReportsCount = $dotDatn ? BaoCaoTienDo::where('sinh_vien_id', $sinhVienId)->where('dot_id', $dotDatn->dot_id)->where('loai_bao_cao', 'DO_AN')->count() : 0;
        $totalReports = $tttnReportsCount + $datnReportsCount;

        // 5. Kết quả điểm số (lấy điểm của đợt mới nhất nếu sinh viên học lại)
        $diemTttn = DB::table('diemthuctap')
            ->where('sinh_vien_id', $sinhVienId)
            ->orderBy('dot_id', 'desc')
            ->first();

        $diemDatn = DB::table('diemtongketdatn')
            ->join('nhomsvda', 'diemtongketdatn.nhom_id', '=', 'nhomsvda.nhom_id')
            ->where('diemtongketdatn.sinh_vien_id', $sinhVienId)
            ->orderBy('nhomsvda.dot_id', 'desc')
            ->select('diemtongketdatn.*')
            ->first();

        $gpa = 0.0;
        $gradesCount = 0;

        if ($diemTttn && $diemTttn->diem_so !== null) {
            $gpa += (float) $diemTttn->diem_so;
            $gradesCount++;
        }
        if ($diemDatn && $diemDatn->diem_tong_ket !== null) {
            $gpa += (float) $diemDatn->diem_tong_ket;
            $gradesCount++;
        }
        $expectedScore = $gradesCount > 0 ? round($gpa / $gradesCount, 2) : 0.0;

        // 6. Lộ trình cá nhân (pendingTasks) và Mốc quan trọng (milestones)
        $pendingTasks = [];
        $milestones = [];

        if ($dotTttn) {
            // Milestones TTTN
            if ($dotTttn->han_dang_ky) {
                $milestones[] = [
                    'date' => Carbon::parse($dotTttn->han_dang_ky)->format('d/m'),
                    'title' => 'Hạn đăng ký TTTN ('.$dotTttn->ten_dot.')',
                ];
            }
            if ($dotTttn->han_nop_bao_cao) {
                $milestones[] = [
                    'date' => Carbon::parse($dotTttn->han_nop_bao_cao)->format('d/m'),
                    'title' => 'Hạn nộp báo cáo TTTN',
                ];
            }

            // Pending tasks TTTN
            if ($tttnInfo['status'] === 'Chưa đăng ký') {
                $deadlineStr = $dotTttn->han_dang_ky ? ' trước '.Carbon::parse($dotTttn->han_dang_ky)->format('d/m') : '';
                $pendingTasks[] = "Khai báo nơi thực tập{$deadlineStr}.";
            } elseif ($tttnInfo['status'] === 'Đang thực tập') {
                $pendingTasks[] = 'Nộp báo cáo tuần thực tập cho giảng viên hướng dẫn.';
            }
        }

        if ($dotDatn) {
            // Milestones ĐATN
            if ($dotDatn->han_dang_ky) {
                $milestones[] = [
                    'date' => Carbon::parse($dotDatn->han_dang_ky)->format('d/m'),
                    'title' => 'Hạn đăng ký đề tài ĐATN',
                ];
            }
            if ($dotDatn->han_nop_bao_cao) {
                $milestones[] = [
                    'date' => Carbon::parse($dotDatn->han_nop_bao_cao)->format('d/m'),
                    'title' => 'Hạn nộp báo cáo ĐATN',
                ];
            }
            if ($dotDatn->ngay_ket_thuc_cham_diem) {
                $milestones[] = [
                    'date' => Carbon::parse($dotDatn->ngay_ket_thuc_cham_diem)->format('d/m'),
                    'title' => 'Công bố kết quả ĐATN',
                ];
            }

            // Pending tasks ĐATN
            if ($datnInfo['status'] === 'Chưa tham gia' || $datnInfo['status'] === 'Chưa chọn đề tài') {
                $deadlineStr = $dotDatn->han_dang_ky ? ' trước '.Carbon::parse($dotDatn->han_dang_ky)->format('d/m') : '';
                $pendingTasks[] = "Đăng ký đề tài ĐATN và thành lập nhóm{$deadlineStr}.";
            } else {
                $pendingTasks[] = 'Cập nhật tiến độ bản thảo đồ án / chương mới.';
            }
        }

        // Lời mời nhóm đang chờ phê duyệt
        $pendingInvites = LoiMoiNhom::where('sinh_vien_duoc_moi_id', $sinhVienId)
            ->where('trang_thai_xac_nhan', 'CHO_XAC_NHAN')
            ->count();
        if ($pendingInvites > 0) {
            $pendingTasks[] = "Bạn có {$pendingInvites} lời mời gia nhập nhóm đồ án đang chờ xác nhận.";
        }

        // Nếu ko có đợt nào thì có thông báo chung
        if (empty($pendingTasks)) {
            $pendingTasks[] = 'Theo dõi thông báo mới nhất từ Khoa / Trường.';
        }

        // 7. Số lượng công ty đối tác đang hoạt động và đã công bố cho sinh viên xem
        $companiesCount = CongTy::where('trang_thai', 'HOAT_DONG')->where('da_cong_bo', true)->count();

        // Trả về cấu trúc JSON đồng bộ
        return response()->json([
            'code' => 200,
            'results' => [
                'object' => [
                    'student' => [
                        'id' => $sinhVien->sinh_vien_id,
                        'studentCode' => $sinhVien->ma_so_sinh_vien,
                        'name' => $sinhVien->ho_ten,
                        'email' => $sinhVien->email,
                        'phone' => $sinhVien->so_dien_thoai,
                        'className' => $sinhVien->lop ? $sinhVien->lop->ten_lop : '',
                    ],
                    'tttn' => $tttnInfo,
                    'datn' => $datnInfo,
                    'reportsCount' => $totalReports,
                    'expectedScore' => $expectedScore,
                    'pendingTasks' => $pendingTasks,
                    'milestones' => $milestones,
                    'companiesCount' => $companiesCount,
                ],
            ],
        ]);
    }
}
