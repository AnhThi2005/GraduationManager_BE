<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use App\Models\Lop;
use App\Models\SinhVien;
use App\Models\GiangVien;
use App\Models\DeTai;
use App\Models\Nhom;
use App\Models\HoiDong;
use App\Models\Dot;

class ExcelDataSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Disable Foreign Key Checks
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');

        // 2. Truncate all data tables
        $tables = [
            'thanhvienhoidong',
            'lichbaove',
            'thanhviennhom',
            'nhomsvda',
            'dangkydetai',
            'diembaocao',
            'diemhoidongbaove',
            'diemthuctap',
            'diemtongketdatn',
            'baocaotiendo',
            'nhanxetbaocao',
            'dangkythuctap',
            'phanconghdtt',
            'loimoinhom',
            'dot_lop',
            'dot_sinhvien',
            'detai',
            'dot',
            'sinhvien',
            'lop',
            'giangvien',
            'hoidong',
            'congty',
            'congtylinhvuc',
            'personal_access_tokens',
            'users',
            'sessions',
            'failed_jobs',
            'job_batches',
            'jobs'
        ];

        foreach ($tables as $table) {
            DB::table($table)->truncate();
        }

        // Re-enable Foreign Key Checks
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');

        // 3. Create the 2 default login lecturers
        $adminLecturer = new GiangVien();
        $adminLecturer->giang_vien_id = 1;
        $adminLecturer->ho_ten = 'Lê Viết Hoàng Nguyên';
        $adminLecturer->email = 'damnguyenanhthi@gmail.com';
        $adminLecturer->so_dien_thoai = '0901111001';
        $adminLecturer->gioi_tinh = 'Nam';
        $adminLecturer->ngay_sinh = '1981-03-15';
        $adminLecturer->hoc_vi = 'ThS';
        $adminLecturer->chuyen_mon = 'Phần mềm';
        $adminLecturer->vai_tro = 'ADMIN';
        $adminLecturer->dang_hoat_dong = 1;
        $adminLecturer->google_id = '106895233109219247327';
        $adminLecturer->save();

        $gvLecturer = new GiangVien();
        $gvLecturer->giang_vien_id = 2;
        $gvLecturer->ho_ten = 'Trần Thị Hoa';
        $gvLecturer->email = 'anhthu03102003@gmail.com';
        $gvLecturer->so_dien_thoai = '0909111222';
        $gvLecturer->gioi_tinh = 'Nữ';
        $gvLecturer->ngay_sinh = '1985-05-10';
        $gvLecturer->hoc_vi = 'ThS';
        $gvLecturer->chuyen_mon = 'Công nghệ phần mềm';
        $gvLecturer->vai_tro = 'GIANG_VIEN';
        $gvLecturer->dang_hoat_dong = 1;
        $gvLecturer->google_id = '108327495054057555252';
        $gvLecturer->save();

        // 4. Create the main academic period (Dot)
        $dot = new Dot();
        $dot->dot_id = 1;
        $dot->giang_vien_id = 1; // ADMIN
        $dot->ten_dot = 'Đồ Án Tốt Nghiệp CĐTH 23';
        $dot->loai_dot = 'DATN';
        $dot->hoc_ky = '2';
        $dot->nam_hoc = '2025-2026';
        $dot->trang_thai = 'DANG_MO';
        $dot->ngay_bat_dau = '2026-03-01';
        $dot->ngay_ket_thuc = '2026-06-30';
        $dot->ngay_bat_dau_dang_ky = '2026-02-01';
        $dot->han_dang_ky = '2026-02-20';
        $dot->han_nop_bao_cao = '2026-05-30';
        $dot->ngay_bat_dau_cham_diem = '2026-06-01';
        $dot->ngay_ket_thuc_cham_diem = '2026-06-25';
        $dot->save();

        // 5. Create 5 Councils (Hoi Dong)
        $councils = [];
        for ($i = 1; $i <= 5; $i++) {
            $hd = new HoiDong();
            $hd->hoi_dong_id = $i;
            $hd->dot_id = $dot->dot_id;
            $hd->ten_hoi_dong = 'Hội đồng ' . $i;
            $hd->ngay_bao_ve = '2026-06-15';
            $hd->gio_bao_ve = '08:00';
            $hd->phong_bao_ve = 'A1.0' . $i;
            $hd->trang_thai = 'DA_CONG_BO';
            $hd->save();

            $councils[$i] = $hd;
        }

        // 6. Read and parse Excel file
        $file = 'D:/GraduationManager_Web/DataSeed/dsNhomDeTai_Private.xlsx';
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        // Helper to normalize and map class names
        $classMap = []; // ten_lop => Lop model
        $lecturerMap = [
            'Lê Viết Hoàng Nguyên' => 1,
            'Trần Thị Hoa' => 2,
        ]; // name => giang_vien_id

        // Accents removal helper for email generation
        $removeAccents = function($str) {
            $str = preg_replace("/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/u", 'a', $str);
            $str = preg_replace("/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/u", 'e', $str);
            $str = preg_replace("/(ì|í|ị|ỉ|ĩ)/u", 'i', $str);
            $str = preg_replace("/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/u", 'o', $str);
            $str = preg_replace("/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/u", 'u', $str);
            $str = preg_replace("/(ỳ|ý|ỵ|ỷ|ỹ)/u", 'y', $str);
            $str = preg_replace("/(đ|đ)/u", 'd', $str);
            $str = preg_replace("/(À|Á|Ạ|Ả|Ã|Â|Ầ|Ấ|Ậ|Ẩ|Ẫ|Ă|Ằ|Ắ|Ặ|Ẳ|Ẵ)/u", 'A', $str);
            $str = preg_replace("/(È|É|Ẹ|Ẻ|Ẽ|Ê|Ề|Ế|Ệ|Ể|Ễ)/u", 'E', $str);
            $str = preg_replace("/(Ì|Í|Ị|Ỉ|Ĩ)/u", 'I', $str);
            $str = preg_replace("/(Ò|Ó|Ọ|Ỏ|Õ|Ô|Ồ|Ố|Ộ|Ổ|Ỗ|Ơ|Ờ|Ớ|Ợ|Ở|Ỡ)/u", 'O', $str);
            $str = preg_replace("/(Ù|Ú|Ụ|Ủ|tilde|Ư|Ừ|Ứ|Ự|Ử|Ữ)/u", 'U', $str);
            $str = preg_replace("/(Ỳ|Ý|Ỵ|Ý|Ỹ)/u", 'Y', $str);
            $str = preg_replace("/(Đ|Đ)/u", 'D', $str);
            return $str;
        };

        $parseClass = function($className) {
            $className = trim($className);
            $normalized = str_replace('Ð', 'Đ', $className);
            
            $bac_dao_tao = 'CAO_DANG';
            if (strpos($normalized, 'CĐN') === 0) {
                $bac_dao_tao = 'CAO_DANG_NGHE';
            }
            
            $khoa_hoc = '2023';
            if (preg_match('/TH\s*(\d{2})/u', $normalized, $matches)) {
                $khoa_hoc = '20' . $matches[1];
            }
            
            $afterTH = preg_replace('/^.*TH\s*\d{2}/u', '', $normalized);
            $afterTH = trim($afterTH);
            
            $chuyen_nganh = 'Lập trình Web'; // Default
            if (stripos($afterTH, 'WEB') === 0) {
                $chuyen_nganh = 'Lập trình Web';
            } elseif (stripos($afterTH, 'DD') === 0 || stripos($afterTH, 'DĐ') === 0) {
                $chuyen_nganh = 'Lập trình di động';
            } elseif (stripos($afterTH, 'MMT') === 0) {
                $chuyen_nganh = 'Mạng máy tính';
            } elseif (stripos($afterTH, 'QTM') === 0) {
                $chuyen_nganh = 'Quản trị mạng';
            } elseif (stripos($afterTH, 'PM') === 0) {
                $chuyen_nganh = 'Công nghệ phần mềm';
            }
            
            return [
                'ten_lop' => $normalized,
                'bac_dao_tao' => $bac_dao_tao,
                'khoa_hoc' => $khoa_hoc,
                'chuyen_nganh' => $chuyen_nganh
            ];
        };

        // Pass 1: Extract all unique classes and lecturers, insert them.
        for ($i = 4; $i <= count($rows); $i++) {
            $row = $rows[$i];
            if (empty($row['A']) || empty($row['B'])) continue;

            // Extract classes
            foreach (['D', 'G', 'J'] as $col) {
                $ten_lop = trim($row[$col] ?? '');
                if ($ten_lop !== '') {
                    $ten_lop_norm = str_replace('Ð', 'Đ', $ten_lop);
                    if (!isset($classMap[$ten_lop_norm])) {
                        $classData = $parseClass($ten_lop_norm);
                        $lop = Lop::create($classData);
                        $classMap[$ten_lop_norm] = $lop;

                        // Associate this class with the academic period
                        DB::table('dot_lop')->insert([
                            'dot_id' => $dot->dot_id,
                            'lop_id' => $lop->lop_id,
                        ]);
                    }
                }
            }

            // Extract lecturers
            foreach (['L', 'M'] as $col) {
                $name = trim($row[$col] ?? '');
                if ($name !== '' && !isset($lecturerMap[$name])) {
                    // Generate clean email
                    $emailName = strtolower(str_replace(' ', '', $removeAccents($name)));
                    $email = $emailName . '@caothang.edu.vn';
                    
                    // Avoid duplicate emails
                    $count = 1;
                    $origEmail = $email;
                    while (GiangVien::where('email', $email)->exists()) {
                        $email = str_replace('@', $count . '@', $origEmail);
                        $count++;
                    }

                    $giangVien = GiangVien::create([
                        'ho_ten' => $name,
                        'email' => $email,
                        'so_dien_thoai' => '0903' . str_pad(rand(0, 999999), 6, '0', STR_PAD_LEFT),
                        'gioi_tinh' => 'Nam',
                        'ngay_sinh' => '1985-01-01',
                        'hoc_vi' => 'ThS',
                        'chuyen_mon' => 'Công nghệ thông tin',
                        'vai_tro' => 'GIANG_VIEN',
                        'dang_hoat_dong' => 1,
                    ]);

                    $lecturerMap[$name] = $giangVien->giang_vien_id;
                }
            }
        }

        // Pass 2: Extract students, groups, topics, assignments, defenses
        $councilDefenseOrder = []; // council_id => order count
        $topicMap = []; // ten_de_tai => DeTai model
        
        for ($i = 4; $i <= count($rows); $i++) {
            $row = $rows[$i];
            if (empty($row['A']) || empty($row['B'])) continue;

            $gvhdName = trim($row['L'] ?? '');
            $gvpbName = trim($row['M'] ?? '');
            $gvhdId = $lecturerMap[$gvhdName] ?? 2; // Default to Trần Thị Hoa
            $gvpbId = $lecturerMap[$gvpbName] ?? 1; // Default to Nguyễn Văn Tài

            // 1. Create or retrieve DeTai (Topic)
            $tenDeTai = trim($row['K'] ?? '');
            if ($tenDeTai === '') {
                $tenDeTai = 'Đề tài ĐATN nhóm STT ' . $row['A'];
            }

            // Count students in group
            $svCount = 0;
            if (!empty($row['B']) && !empty($row['C']) && !empty($row['D'])) $svCount++;
            if (!empty($row['E']) && !empty($row['F']) && !empty($row['G'])) $svCount++;
            if (!empty($row['H']) && !empty($row['I']) && !empty($row['J'])) $svCount++;
            $currentGroupSvCount = $svCount > 0 ? $svCount : 2;

            if (isset($topicMap[$tenDeTai])) {
                $deTai = $topicMap[$tenDeTai];
                $deTai->so_luong_sv_toi_da += $currentGroupSvCount;
                $deTai->save();
            } else {
                // Determine topic branch (huong_de_tai) based on first student's class specialization
                $firstStudentClass = trim($row['D'] ?? '');
                $firstStudentClassNorm = str_replace('Ð', 'Đ', $firstStudentClass);
                $parsedClass = $classMap[$firstStudentClassNorm] ?? null;
                $huongDeTai = 'PHAN_MEM';
                if ($parsedClass && ($parsedClass->chuyen_nganh === 'Mạng máy tính' || $parsedClass->chuyen_nganh === 'Quản trị mạng')) {
                    $huongDeTai = 'MANG_MAY_TINH';
                }

                $deTai = DeTai::create([
                    'dot_id' => $dot->dot_id,
                    'giang_vien_id' => $gvhdId,
                    'ten_de_tai' => $tenDeTai,
                    'mo_ta' => 'Đồ án tốt nghiệp khóa 2023',
                    'file_mo_ta' => null,
                    'so_luong_sv_toi_da' => $currentGroupSvCount,
                    'huong_de_tai' => $huongDeTai,
                    'trang_thai' => 'DA_DUYET',
                ]);
                $topicMap[$tenDeTai] = $deTai;
            }

            // 2. Create HoiDong mapping
            $councilNum = intval(trim($row['N'] ?? '0'));
            $councilId = ($councilNum >= 1 && $councilNum <= 5) ? $councilNum : null;

            if ($councilId) {
                // Assign GVHD as UY_VIEN and GVPB as PHAN_BIEN in council
                // Check if they are already in the council
                $existsGvhd = DB::table('thanhvienhoidong')
                    ->where('hoi_dong_id', $councilId)
                    ->where('giang_vien_id', $gvhdId)
                    ->exists();
                if (!$existsGvhd) {
                    DB::table('thanhvienhoidong')->insert([
                        'hoi_dong_id' => $councilId,
                        'giang_vien_id' => $gvhdId,
                        'vai_tro' => 'UY_VIEN',
                    ]);
                }

                $existsGvpb = DB::table('thanhvienhoidong')
                    ->where('hoi_dong_id', $councilId)
                    ->where('giang_vien_id', $gvpbId)
                    ->exists();
                if (!$existsGvpb) {
                    DB::table('thanhvienhoidong')->insert([
                        'hoi_dong_id' => $councilId,
                        'giang_vien_id' => $gvpbId,
                        'vai_tro' => 'PHAN_BIEN',
                    ]);
                }
            }

            // 3. Create Nhom (Group)
            $nhom = Nhom::create([
                'de_tai_id' => $deTai->de_tai_id,
                'dot_id' => $dot->dot_id,
                'hoi_dong_id' => $councilId,
                'trang_thai_nhom' => 'DU_THANH_VIEN',
                'trang_thai_duyet' => 'DA_DUYET',
                'ngay_dang_ky' => now(),
            ]);

            // Also insert into dangkydetai (Topic Registration) table
            DB::table('dangkydetai')->insert([
                'nhom_id' => $nhom->nhom_id,
                'de_tai_id' => $deTai->de_tai_id,
                'trang_thai_duyet' => 'DA_DUYET',
                'ngay_dang_ky' => now(),
                'ly_do_tu_choi' => null,
            ]);

            // 4. Create Group Defense Schedule (LichBaoVe)
            if ($councilId) {
                if (!isset($councilDefenseOrder[$councilId])) {
                    $councilDefenseOrder[$councilId] = 0;
                }
                $councilDefenseOrder[$councilId]++;
                $order = $councilDefenseOrder[$councilId];
                
                // 30 mins per group, starting at 08:00
                $minutesToAdd = ($order - 1) * 30;
                $startTime = date('Y-m-d H:i:s', strtotime("2026-06-15 08:00:00 + $minutesToAdd minutes"));

                DB::table('lichbaove')->insert([
                    'hoi_dong_id' => $councilId,
                    'nhom_id' => $nhom->nhom_id,
                    'thoi_gian_bat_dau' => $startTime,
                    'thu_tu' => $order,
                    'ghi_chu' => 'Báo cáo bảo vệ đồ án',
                ]);
            }

            // 5. Create Students and Group Members
            $studentsData = [
                ['mssv' => 'B', 'name' => 'C', 'class' => 'D', 'is_leader' => 1],
                ['mssv' => 'E', 'name' => 'F', 'class' => 'G', 'is_leader' => 0],
                ['mssv' => 'H', 'name' => 'I', 'class' => 'J', 'is_leader' => 0],
            ];

            foreach ($studentsData as $svData) {
                $mssvCol = $svData['mssv'];
                $nameCol = $svData['name'];
                $classCol = $svData['class'];
                $isLeader = $svData['is_leader'];

                $mssv = trim($row[$mssvCol] ?? '');
                $hoTen = trim($row[$nameCol] ?? '');
                $tenLop = trim($row[$classCol] ?? '');
                $tenLopNorm = str_replace('Ð', 'Đ', $tenLop);

                if ($mssv !== '' && $hoTen !== '') {
                    // Normalize name spaces
                    $hoTen = preg_replace('/\s+/u', ' ', $hoTen);
                    
                    // Find class ID
                    $lop = $classMap[$tenLopNorm] ?? null;
                    $lopId = $lop ? $lop->lop_id : null;

                    $reason = null;
                    if ($lop && intval($lop->khoa_hoc) < 2023) {
                        $reason = 'Rớt đợt trước';
                    }

                    // Detect gender based on name containing "Thị" or "Thi"
                    $gender = 'Nam';
                    if (preg_match('/\b(thi|thị)\b/ui', $hoTen)) {
                        $gender = 'Nữ';
                    }

                    // Create SinhVien (if already created by another row, retrieve it, but MSSV is unique)
                    $sinhVien = SinhVien::where('ma_so_sinh_vien', $mssv)->first();
                    if (!$sinhVien) {
                        $sinhVien = SinhVien::create([
                            'ma_so_sinh_vien' => $mssv,
                            'ho_ten' => $hoTen,
                            'email' => $mssv . '@caothang.edu.vn',
                            'so_dien_thoai' => '09' . str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT),
                            'gioi_tinh' => $gender,
                            'ngay_sinh' => '2005-01-01',
                            'lop_id' => $lopId,
                            'dang_hoat_dong' => 1,
                        ]);
                    }

                    // Map student to academic period (Dot)
                    DB::table('dot_sinhvien')->insertOrIgnore([
                        'dot_id' => $dot->dot_id,
                        'sinh_vien_id' => $sinhVien->sinh_vien_id,
                        'ly_do' => $reason,
                    ]);

                    // Map student to Group (ThanhVienNhom)
                    DB::table('thanhviennhom')->insert([
                        'nhom_id' => $nhom->nhom_id,
                        'sinh_vien_id' => $sinhVien->sinh_vien_id,
                        'la_truong_nhom' => $isLeader,
                        'dieu_kien_lam_do_an' => 'DAT',
                    ]);

                    // Create Report Scores record (DiemBaoCao)
                    DB::table('diembaocao')->insert([
                        'nhom_id' => $nhom->nhom_id,
                        'sinh_vien_id' => $sinhVien->sinh_vien_id,
                        'giang_vien_hd_id' => $gvhdId,
                        'giang_vien_pb_id' => $gvpbId,
                        'diem_gvhd' => null,
                        'diem_gvpb' => null,
                        'diem_trung_binh' => null,
                    ]);

                }
            }
        }

        // Pass 3: Finalize Councils, insert DiemHoiDongBaoVe and seed LoiMoiNhom
        // 1. Assign CHU_TICH for each council (must not be giang_vien_id = 1 unless no other members exist)
        for ($cId = 1; $cId <= 5; $cId++) {
            // Find a member in this council who is NOT giang_vien_id = 1
            $member = DB::table('thanhvienhoidong')
                ->where('hoi_dong_id', $cId)
                ->where('giang_vien_id', '!=', 1)
                ->first();

            if ($member) {
                // Update their vai_tro to CHU_TICH
                DB::table('thanhvienhoidong')
                    ->where('hoi_dong_id', $cId)
                    ->where('giang_vien_id', $member->giang_vien_id)
                    ->update(['vai_tro' => 'CHU_TICH']);
            } else {
                // Fallback
                $anyMember = DB::table('thanhvienhoidong')
                    ->where('hoi_dong_id', $cId)
                    ->first();
                if ($anyMember) {
                    DB::table('thanhvienhoidong')
                        ->where('hoi_dong_id', $cId)
                        ->where('giang_vien_id', $anyMember->giang_vien_id)
                        ->update(['vai_tro' => 'CHU_TICH']);
                }
            }
        }

        // 2. Insert DiemHoiDongBaoVe for all finalized council members per group student
        $allGroups = Nhom::with('members')->whereNotNull('hoi_dong_id')->get();
        foreach ($allGroups as $g) {
            $councilMembers = DB::table('thanhvienhoidong')
                ->where('hoi_dong_id', $g->hoi_dong_id)
                ->pluck('giang_vien_id');

            foreach ($g->members as $m) {
                foreach ($councilMembers as $judgeId) {
                    DB::table('diemhoidongbaove')->insert([
                        'sinh_vien_id' => $m->sinh_vien_id,
                        'nhom_id' => $g->nhom_id,
                        'giang_vien_id' => $judgeId,
                        'diem_thuyet_trinh' => null,
                        'diem_demo' => null,
                        'diem_van_dap' => null,
                        'diem_bao_ve' => null,
                    ]);
                }
            }
        }

        // 3. Seed LoiMoiNhom (Group Invitations)
        // For each group, the leader invites all other members of the group, and they accept.
        $allGroupsWithMembers = Nhom::with('members')->get();
        foreach ($allGroupsWithMembers as $g) {
            // Find the leader
            $leaderPivot = $g->members->first(function($m) {
                return (bool) $m->pivot->la_truong_nhom;
            });

            if ($leaderPivot) {
                // The leader invites all other members
                foreach ($g->members as $m) {
                    if ($m->sinh_vien_id !== $leaderPivot->sinh_vien_id) {
                        DB::table('loimoinhom')->insert([
                            'nhom_id' => $g->nhom_id,
                            'sinh_vien_duoc_moi_id' => $m->sinh_vien_id,
                            'trang_thai_xac_nhan' => 'DA_CHAP_NHAN',
                            'ngay_tao' => now(),
                        ]);
                    }
                }
            }
        }

        // 4. Seed TTTN Period (Dot ID = 2) and assignments from PHANCONGHD_TTTN_CDTH23.json
        $dotTttn = new Dot();
        $dotTttn->dot_id = 2;
        $dotTttn->giang_vien_id = 1; // ADMIN
        $dotTttn->ten_dot = 'Thực Tập Tốt Nghiệp CĐTH 23';
        $dotTttn->loai_dot = 'TTTN';
        $dotTttn->hoc_ky = '2';
        $dotTttn->nam_hoc = '2025-2026';
        $dotTttn->trang_thai = 'DANG_MO';
        $dotTttn->ngay_bat_dau = '2026-03-01';
        $dotTttn->ngay_ket_thuc = '2026-06-30';
        $dotTttn->ngay_bat_dau_dang_ky = '2026-02-01';
        $dotTttn->han_dang_ky = '2026-02-20';
        $dotTttn->han_nop_bao_cao = '2026-05-30';
        $dotTttn->ngay_bat_dau_cham_diem = '2026-06-01';
        $dotTttn->ngay_ket_thuc_cham_diem = '2026-06-25';
        $dotTttn->save();

        $jsonFile = 'D:/GraduationManager_Web/DataSeed/PHANCONGHD_TTTN_CDTH23.json';
        if (file_exists($jsonFile)) {
            $tttnData = json_decode(file_get_contents($jsonFile), true);

            foreach ($tttnData as $item) {
                $mssv = trim($item['mssv']);
                $hoTenSv = trim($item['ho_ten_sv']);
                $dob = trim($item['dob']);
                $className = trim($item['lop']);
                $gvhdName = trim($item['gvhd']);

                // 1. Process Class
                if (!isset($classMap[$className])) {
                    $classData = $parseClass($className);
                    $lop = Lop::create($classData);
                    $classMap[$className] = $lop;
                }
                $lop = $classMap[$className];

                // Associate class with TTTN Period (Dot ID = 2)
                $existsDotLop = DB::table('dot_lop')
                    ->where('dot_id', 2)
                    ->where('lop_id', $lop->lop_id)
                    ->exists();
                if (!$existsDotLop) {
                    DB::table('dot_lop')->insert([
                        'dot_id' => 2,
                        'lop_id' => $lop->lop_id,
                    ]);
                }

                // 2. Process Lecturer
                if (!isset($lecturerMap[$gvhdName])) {
                    $emailName = $removeAccents($gvhdName);
                    $emailName = str_replace(' ', '', strtolower($emailName));
                    $email = $emailName . '@caothang.edu.vn';

                    // Ensure email is unique
                    $existsEmail = GiangVien::where('email', $email)->exists();
                    if ($existsEmail) {
                        $email = $emailName . rand(1, 99) . '@caothang.edu.vn';
                    }

                    $giangVien = GiangVien::create([
                        'ho_ten' => $gvhdName,
                        'email' => $email,
                        'so_dien_thoai' => '09' . str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT),
                        'gioi_tinh' => preg_match('/\b(thi|thị)\b/ui', $gvhdName) ? 'Nữ' : 'Nam',
                        'ngay_sinh' => '1985-01-01',
                        'hoc_vi' => 'ThS',
                        'chuyen_mon' => 'Công nghệ thông tin',
                        'vai_tro' => 'GIANG_VIEN',
                        'dang_hoat_dong' => 1,
                    ]);

                    $lecturerMap[$gvhdName] = $giangVien->giang_vien_id;
                }
                $gvhdId = $lecturerMap[$gvhdName];

                // 3. Process Student
                $sinhVien = SinhVien::where('ma_so_sinh_vien', $mssv)->first();
                if (!$sinhVien) {
                    // Convert DOB from DD/MM/YYYY to YYYY-MM-DD
                    $dobDb = '2005-01-01';
                    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $dob, $dobMatches)) {
                        $dobDb = $dobMatches[3] . '-' . $dobMatches[2] . '-' . $dobMatches[1];
                    }

                    $sinhVien = SinhVien::create([
                        'ma_so_sinh_vien' => $mssv,
                        'ho_ten' => $hoTenSv,
                        'email' => $mssv . '@caothang.edu.vn',
                        'so_dien_thoai' => '09' . str_pad(rand(0, 99999999), 8, '0', STR_PAD_LEFT),
                        'gioi_tinh' => preg_match('/\b(thi|thị)\b/ui', $hoTenSv) ? 'Nữ' : 'Nam',
                        'ngay_sinh' => $dobDb,
                        'lop_id' => $lop->lop_id,
                        'dang_hoat_dong' => 1,
                    ]);
                }

                // Map student to TTTN academic period (Dot ID = 2)
                $reason = null;
                if ($lop && intval($lop->khoa_hoc) < 2023) {
                    $reason = 'Rớt đợt trước';
                }

                DB::table('dot_sinhvien')->insertOrIgnore([
                    'dot_id' => 2,
                    'sinh_vien_id' => $sinhVien->sinh_vien_id,
                    'ly_do' => $reason,
                ]);

                // 4. Create TTTN Guidance Assignment (PhanCongHdtt)
                DB::table('phanconghdtt')->insert([
                    'giang_vien_id' => $gvhdId,
                    'sinh_vien_id' => $sinhVien->sinh_vien_id,
                    'dot_id' => 2,
                    'da_cong_bo' => true,
                    'ngay_phan_cong' => now(),
                ]);
            }
        }
    }
}
