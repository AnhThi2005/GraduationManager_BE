<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use App\Models\Dot;
use App\Models\Lop;
use App\Models\DeTai;
use App\Models\Nhom;
use App\Models\SinhVien;
use App\Models\GiangVien;
use App\Models\HoiDong;
use App\Models\BaoCaoTienDo;
use App\Models\DangKyThucTap;
use App\Models\PhanCongHdtt;

class LecturerDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Ensure required Lecturers exist
        $teacher = GiangVien::updateOrCreate(
            ['email' => 'thoa@caothang.edu.vn'],
            [
                'giang_vien_id' => 2,
                'ho_ten' => 'Trần Thị Hoa',
                'so_dien_thoai' => '0909111222',
                'gioi_tinh' => 'Nữ',
                'ngay_sinh' => '1985-05-10',
                'hoc_vi' => 'ThS',
                'chuyen_mon' => 'Công nghệ phần mềm',
                'vai_tro' => 'GIANG_VIEN',
                'dang_hoat_dong' => 1
            ]
        );

        $adminTeacher = GiangVien::updateOrCreate(
            ['email' => 'tvai@caothang.edu.vn'],
            [
                'giang_vien_id' => 1,
                'ho_ten' => 'Nguyễn Văn Tài',
                'so_dien_thoai' => '0901111001',
                'gioi_tinh' => 'Nam',
                'ngay_sinh' => '1981-03-15',
                'hoc_vi' => 'ThS',
                'chuyen_mon' => 'Phần mềm',
                'vai_tro' => 'ADMIN',
                'dang_hoat_dong' => 1
            ]
        );

        $otherTeacher = GiangVien::updateOrCreate(
            ['email' => 'ptlan@caothang.edu.vn'],
            [
                'giang_vien_id' => 3,
                'ho_ten' => 'Phạm Thị Lan',
                'so_dien_thoai' => '0901222002',
                'gioi_tinh' => 'Nữ',
                'ngay_sinh' => '1988-08-20',
                'hoc_vi' => 'TS',
                'chuyen_mon' => 'Hệ thống thông tin',
                'vai_tro' => 'GIANG_VIEN',
                'dang_hoat_dong' => 1
            ]
        );

        // 2. Ensure academic periods (Dot) exist
        $period1 = Dot::updateOrCreate(
            ['dot_id' => 1],
            [
                'ten_dot' => 'Thực Tập Tốt Nghiệp – Kỳ 1 2025-2026',
                'loai_dot' => 'TTTN',
                'hoc_ky' => '1',
                'nam_hoc' => '2025-2026',
                'trang_thai' => 'DA_DONG',
                'ngay_bat_dau' => '2025-09-01',
                'ngay_ket_thuc' => '2025-11-30',
                'han_dang_ky' => '2025-08-25',
                'giang_vien_id' => 1
            ]
        );

        $period2 = Dot::updateOrCreate(
            ['dot_id' => 2],
            [
                'ten_dot' => 'Đồ Án Tốt Nghiệp – Kỳ 1 2025-2026 (Bổ sung/Phụ)',
                'loai_dot' => 'DATN',
                'hoc_ky' => '1',
                'nam_hoc' => '2025-2026',
                'trang_thai' => 'CHAM_DIEM',
                'ngay_bat_dau' => '2025-12-01',
                'ngay_ket_thuc' => '2026-02-28',
                'han_dang_ky' => '2025-11-20',
                'giang_vien_id' => 1
            ]
        );

        $period3 = Dot::updateOrCreate(
            ['dot_id' => 3],
            [
                'ten_dot' => 'Đồ Án Tốt Nghiệp – Kỳ 2 2025-2026 (Chính thức)',
                'loai_dot' => 'DATN',
                'hoc_ky' => '2',
                'nam_hoc' => '2025-2026',
                'trang_thai' => 'CHO_MO',
                'ngay_bat_dau' => '2026-03-01',
                'ngay_ket_thuc' => '2026-06-30',
                'han_dang_ky' => '2026-02-20',
                'giang_vien_id' => 1
            ]
        );

        $period4 = Dot::updateOrCreate(
            ['dot_id' => 4],
            [
                'ten_dot' => 'Thực Tập Tốt Nghiệp – Kỳ 1 2026-2027 (Mới)',
                'loai_dot' => 'TTTN',
                'hoc_ky' => '1',
                'nam_hoc' => '2026-2027',
                'trang_thai' => 'DANG_MO',
                'ngay_bat_dau' => '2026-07-01',
                'ngay_ket_thuc' => '2026-09-30',
                'han_dang_ky' => '2026-06-25',
                'giang_vien_id' => 1
            ]
        );

        // Ensure periods are mapped to classes
        foreach ([1, 2, 3, 4] as $pId) {
            foreach ([1, 2, 3, 4, 5] as $cId) {
                DB::table('dot_lop')->updateOrInsert(
                    ['dot_id' => $pId, 'lop_id' => $cId]
                );
            }
        }

        // 3. Ensure sample Students exist
        $studentNames = [
            1 => ['Nguyễn Minh Khoa', '0306231001@caothang.edu.vn', '0306231001'],
            2 => ['Trần Thị Bảo Châu', '0306231002@caothang.edu.vn', '0306231002'],
            3 => ['Lê Quốc Huy', '0306231003@caothang.edu.vn', '0306231003'],
            4 => ['Phạm Thị Thu Hằng', '0306231004@caothang.edu.vn', '0306231004'],
            5 => ['Hoàng Văn Tuấn', '0306231005@caothang.edu.vn', '0306231005']
        ];

        foreach ($studentNames as $sId => $info) {
            SinhVien::updateOrCreate(
                ['sinh_vien_id' => $sId],
                [
                    'ho_ten' => $info[0],
                    'email' => $info[1],
                    'ma_so_sinh_vien' => $info[2],
                    'so_dien_thoai' => '0912' . str_pad($sId, 6, '0', STR_PAD_LEFT),
                    'gioi_tinh' => ($sId % 2 === 0) ? 'Nữ' : 'Nam',
                    'ngay_sinh' => '2005-04-1' . $sId,
                    'lop_id' => 1, // CDTH23WEBA
                    'dang_hoat_dong' => 1
                ]
            );
        }

        // 4. CLEAN UP old demo data to ensure seeder is repeatable
        // Clean comments & progress reports
        $reportIds = DB::table('baocaotiendo')->whereIn('dot_id', [3, 4])->pluck('bao_cao_id')->merge([101, 102, 103, 104, 105])->unique();
        DB::table('nhanxetbaocao')->whereIn('bao_cao_id', $reportIds)->orWhere('giang_vien_id', 2)->orWhereIn('nhan_xet_id', [101, 102])->delete();
        DB::table('baocaotiendo')->whereIn('dot_id', [3, 4])->orWhereIn('bao_cao_id', [101, 102, 103, 104, 105])->delete();

        // Clean group members & groups
        $groupIds = DB::table('nhomsvda')->whereIn('dot_id', [3])->pluck('nhom_id')->merge([101, 102])->unique();
        DB::table('thanhviennhom')->whereIn('nhom_id', $groupIds)->delete();
        DB::table('nhomsvda')->whereIn('dot_id', [3])->orWhereIn('nhom_id', [101, 102])->delete();

        // Clean council members & councils
        DB::table('thanhvienhoidong')->whereIn('hoi_dong_id', [101])->orWhere('giang_vien_id', 2)->delete();
        DB::table('hoidong')->whereIn('dot_id', [3])->orWhere('hoi_dong_id', 101)->delete();

        // Clean teacher topics, TTTN assignments, and internship registrations
        DB::table('detai')->where('giang_vien_id', 2)->orWhereIn('de_tai_id', [101, 102, 103])->delete();
        DB::table('phanconghdtt')->where('giang_vien_id', 2)->orWhereIn('phan_cong_hd_id', [101, 102, 103])->delete();
        DB::table('dangkythuctap')->whereIn('dot_id', [1, 4])->delete();

        // 5. SEED: Topics (DeTai) proposed by Trần Thị Hoa
        $topic1 = DeTai::create([
            'de_tai_id' => 101,
            'ten_de_tai' => 'Hệ thống IoT giám sát nông nghiệp và tưới nước tự động',
            'dot_id' => 3,
            'giang_vien_id' => 2,
            'mo_ta' => 'Xây dựng ứng dụng và mạch IoT thu thập độ ẩm đất, nhiệt độ môi trường và tự động kích hoạt máy bơm nước qua web.',
            'so_luong_sv_toi_da' => 4,
            'huong_de_tai' => 'PHAN_MEM',
            'trang_thai' => 'DA_DUYET'
        ]);

        $topic2 = DeTai::create([
            'de_tai_id' => 102,
            'ten_de_tai' => 'Ứng dụng Blockchain cho quản lý chuỗi cung ứng thực phẩm',
            'dot_id' => 3,
            'giang_vien_id' => 2,
            'mo_ta' => 'Sử dụng Smart Contract lưu trữ vòng đời và lịch trình vận chuyển của thực phẩm từ nông trại đến siêu thị.',
            'so_luong_sv_toi_da' => 3,
            'huong_de_tai' => 'PHAN_MEM',
            'trang_thai' => 'DA_DUYET'
        ]);

        $topic3 = DeTai::create([
            'de_tai_id' => 103,
            'ten_de_tai' => 'Ứng dụng Machine Learning dự đoán biến động giá chứng khoán',
            'dot_id' => 3,
            'giang_vien_id' => 2,
            'mo_ta' => 'Sử dụng mô hình LSTM để phân tích và đưa ra dự đoán ngắn hạn về giá các mã cổ phiếu phổ biến.',
            'so_luong_sv_toi_da' => 4,
            'huong_de_tai' => 'PHAN_MEM',
            'trang_thai' => 'CHO_DUYET'
        ]);

        // 6. SEED: Groups (Nhom) registered for these topics
        $group1 = Nhom::create([
            'nhom_id' => 101,
            'de_tai_id' => 101,
            'dot_id' => 3,
            'trang_thai_nhom' => 'DU_THANH_VIEN',
            'trang_thai_duyet' => 'DA_DUYET',
            'ket_qua_huong_dan' => null,
            'nhan_xet_phan_bien' => null,
            'ket_qua_phan_bien' => null
        ]);

        $group2 = Nhom::create([
            'nhom_id' => 102,
            'de_tai_id' => 102,
            'dot_id' => 3,
            'trang_thai_nhom' => 'DU_THANH_VIEN',
            'trang_thai_duyet' => 'CHO_DUYET',
            'ket_qua_huong_dan' => null,
            'nhan_xet_phan_bien' => null,
            'ket_qua_phan_bien' => null
        ]);

        // 7. SEED: Group members (thanhviennhom)
        DB::table('thanhviennhom')->insert([
            [
                'nhom_id' => 101,
                'sinh_vien_id' => 1, // Nguyễn Minh Khoa
                'la_truong_nhom' => 1,
                'dieu_kien_lam_do_an' => 'DAT'
            ],
            [
                'nhom_id' => 101,
                'sinh_vien_id' => 2, // Trần Thị Bảo Châu
                'la_truong_nhom' => 0,
                'dieu_kien_lam_do_an' => 'DAT'
            ],
            [
                'nhom_id' => 102,
                'sinh_vien_id' => 3, // Lê Quốc Huy
                'la_truong_nhom' => 1,
                'dieu_kien_lam_do_an' => 'DAT'
            ],
            [
                'nhom_id' => 102,
                'sinh_vien_id' => 4, // Phạm Thị Thu Hằng
                'la_truong_nhom' => 0,
                'dieu_kien_lam_do_an' => 'DAT'
            ]
        ]);

        // 8. SEED: Progress Reports (BaoCaoTienDo) for DATN
        $report1 = BaoCaoTienDo::create([
            'bao_cao_id' => 101,
            'sinh_vien_id' => 1,
            'dot_id' => 3,
            'tuan_so' => 1,
            'noi_dung' => 'Tìm hiểu đề tài, thiết kế sơ đồ khối phần cứng và cấu trúc phần mềm.',
            'duong_dan_file' => 'tailen/bao-cao-do-an-t1-sv1.pdf',
            'trang_thai' => 'DA_NOP',
            'loai_bao_cao' => 'DO_AN',
            'thoi_gian_nop' => '2026-05-15 08:30:00'
        ]);

        $report2 = BaoCaoTienDo::create([
            'bao_cao_id' => 102,
            'sinh_vien_id' => 1,
            'dot_id' => 3,
            'tuan_so' => 2,
            'noi_dung' => 'Xây dựng cơ sở dữ liệu MySQL và viết REST API cho thiết bị IoT kết nối.',
            'duong_dan_file' => 'tailen/bao-cao-do-an-t2-sv1.pdf',
            'trang_thai' => 'DA_NOP',
            'loai_bao_cao' => 'DO_AN',
            'thoi_gian_nop' => '2026-05-22 08:30:00'
        ]);

        $report3 = BaoCaoTienDo::create([
            'bao_cao_id' => 103,
            'sinh_vien_id' => 1,
            'dot_id' => 3,
            'tuan_so' => 3,
            'noi_dung' => 'Hoàn thiện giao diện dashboard hiển thị đồ thị và các nút bấm điều khiển máy bơm.',
            'duong_dan_file' => 'tailen/bao-cao-do-an-t3-sv1.pdf',
            'trang_thai' => 'DA_NOP',
            'loai_bao_cao' => 'DO_AN',
            'thoi_gian_nop' => '2026-05-29 08:30:00'
        ]);

        // Add feedback for week 3 report
        DB::table('nhanxetbaocao')->insert([
            'nhan_xet_id' => 101,
            'bao_cao_id' => 103,
            'giang_vien_id' => 2,
            'noi_dung' => 'Bản thiết kế giao diện Dashboard rất trực quan. Các biểu đồ chạy tốt, cần cải tiến thêm bảo mật API.',
            'danh_gia' => 'DAT',
            'loai_nhan_xet' => 'DO_AN'
        ]);

        // 9. SEED: Councils (HoiDong) & members
        $council1 = HoiDong::create([
            'hoi_dong_id' => 101,
            'dot_id' => 3,
            'ten_hoi_dong' => 'Hội đồng 1 - Công nghệ Web và IoT',
            'ngay_bao_ve' => '2026-06-28',
            'gio_bao_ve' => '08:00:00',
            'phong_bao_ve' => 'Phòng A.102',
            'trang_thai' => 'DA_CONG_BO'
        ]);

        DB::table('thanhvienhoidong')->insert([
            [
                'hoi_dong_id' => 101,
                'giang_vien_id' => 1, // Nguyễn Văn Tài
                'vai_tro' => 'CHU_TICH'
            ],
            [
                'hoi_dong_id' => 101,
                'giang_vien_id' => 2, // Trần Thị Hoa
                'vai_tro' => 'UY_VIEN'
            ],
            [
                'hoi_dong_id' => 101,
                'giang_vien_id' => 3, // Phạm Thị Lan
                'vai_tro' => 'PHAN_BIEN'
            ]
        ]);

        // Link Group 101 to Council 101 for defense / grading
        $group1->hoi_dong_id = 101;
        $group1->save();

        // 10. SEED: TTTN Guidance Assignments (phanconghdtt) for Trần Thị Hoa in TTTN period (dot_id = 4)
        PhanCongHdtt::create([
            'phan_cong_hd_id' => 101,
            'giang_vien_id' => 2,
            'sinh_vien_id' => 1,
            'dot_id' => 4,
            'da_cong_bo' => 1
        ]);

        PhanCongHdtt::create([
            'phan_cong_hd_id' => 102,
            'giang_vien_id' => 2,
            'sinh_vien_id' => 2,
            'dot_id' => 4,
            'da_cong_bo' => 1
        ]);

        PhanCongHdtt::create([
            'phan_cong_hd_id' => 103,
            'giang_vien_id' => 2,
            'sinh_vien_id' => 3,
            'dot_id' => 4,
            'da_cong_bo' => 1
        ]);

        // Seed internship registrations (dangkythuctap) for guided students in Period 4
        DangKyThucTap::create([
            'sinh_vien_id' => 1,
            'dot_id' => 4,
            'cong_ty_id' => 1, // FPT Software
            'nguoi_huong_dan' => 'Anh Nguyễn Thành Nam',
            'sdt_huong_dan' => '0901234567',
            'vi_tri_thuc_tap' => 'Lập trình viên ReactJS',
            'thoi_gian_thuc_tap' => '3 tháng',
            'dia_chi_thuc_tap' => 'Lô T2-4 Đường D1, Khu Công Nghệ Cao, Quận 9',
            'trang_thai' => 'DA_DUYET'
        ]);

        DangKyThucTap::create([
            'sinh_vien_id' => 2,
            'dot_id' => 4,
            'cong_ty_id' => 2, // VNG
            'nguoi_huong_dan' => 'Chị Lê Thị Thanh',
            'sdt_huong_dan' => '0902345678',
            'vi_tri_thuc_tap' => 'QA Intern',
            'thoi_gian_thuc_tap' => '3 tháng',
            'dia_chi_thuc_tap' => 'VNG Campus, Đường số 13, Quận 7',
            'trang_thai' => 'DA_DUYET'
        ]);

        // 11. SEED: TTTN progress reports
        BaoCaoTienDo::create([
            'bao_cao_id' => 104,
            'sinh_vien_id' => 1,
            'dot_id' => 4,
            'tuan_so' => 1,
            'noi_dung' => 'Báo cáo TTTN Tuần 1: Nhận nhiệm vụ, cài đặt môi trường phát triển và đọc tài liệu dự án.',
            'duong_dan_file' => 'tailen/nhat-ky-tt-t1-sv1.pdf',
            'trang_thai' => 'DA_NOP',
            'loai_bao_cao' => 'THUC_TAP',
            'thoi_gian_nop' => '2026-06-15 09:00:00'
        ]);

        BaoCaoTienDo::create([
            'bao_cao_id' => 105,
            'sinh_vien_id' => 1,
            'dot_id' => 4,
            'tuan_so' => 2,
            'noi_dung' => 'Báo cáo TTTN Tuần 2: Xây dựng UI form đăng ký đăng nhập, kết nối API mock.',
            'duong_dan_file' => 'tailen/nhat-ky-tt-t2-sv1.pdf',
            'trang_thai' => 'DA_NOP',
            'loai_bao_cao' => 'THUC_TAP',
            'thoi_gian_nop' => '2026-06-22 09:00:00'
        ]);

        // Feedback for TTTN Week 2 Report
        DB::table('nhanxetbaocao')->insert([
            'nhan_xet_id' => 102,
            'bao_cao_id' => 105,
            'giang_vien_id' => 2,
            'noi_dung' => 'Tiến độ thực tập tốt. Đã nắm bắt được nghiệp vụ và công cụ làm việc của công ty.',
            'danh_gia' => 'DAT',
            'loai_nhan_xet' => 'THUC_TAP'
        ]);
    }
}
