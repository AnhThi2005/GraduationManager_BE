<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuanLyNguoiDung\CapNhatNguoiDungRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\LocNguoiDungUnifiedRequest;
use App\Http\Requests\Admin\QuanLyNguoiDung\ThemNguoiDungRequest;
use App\Models\GiangVien;
use App\Models\Lop;
use App\Models\SinhVien;
use App\Services\NguoiDungService;
use App\Services\RealtimeService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\IOFactory;

class NguoiDungController extends Controller
{
    protected $nguoiDungService;

    public function __construct(NguoiDungService $nguoiDungService)
    {
        $this->nguoiDungService = $nguoiDungService;
    }

    // ==========================================================
    // UNIFIED APIS MATCHING FRONTEND REQUIREMENTS
    // ==========================================================

    /**
     * Lấy danh sách (Sinh viên hoặc Giảng viên) dựa theo tham số role
     */
    public function layDanhSach(LocNguoiDungUnifiedRequest $request)
    {
        $role = $request->input('role', 'student');
        $limit = $request->input('limit', 10);
        $keyword = $request->input('keyword');
        $className = $request->input('className');
        $status = $request->input('status');

        $filters = [];
        if (! empty($keyword)) {
            $filters['ho_ten'] = $keyword;
            $filters['ma_so_sinh_vien'] = $keyword; // map cho sinh viên
        }
        if (! empty($className)) {
            $filters['ten_lop'] = $className; // map cho sinh viên
            $filters['chuyen_mon'] = $className; // map cho giảng viên
        }
        if (isset($status)) {
            $filters['dang_hoat_dong'] = ($status === 'active' || $status === '1') ? 1 : 0;
        }

        if ($role === 'teacher') {
            $paginator = $this->nguoiDungService->locGiangVien($filters, $limit);
            $rows = collect($paginator->items())->map(function ($gv) {
                return $this->transformTeacher($gv);
            })->all();
        } else {
            $paginator = $this->nguoiDungService->locSinhVien($filters, $limit);
            $rows = collect($paginator->items())->map(function ($sv) {
                return $this->transformStudent($sv);
            })->all();
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $rows,
                    'total' => $paginator->total(),
                ],
            ],
            'pagination' => [
                'total' => $paginator->total(),
                'totalPages' => $paginator->lastPage(),
                'limit' => $paginator->perPage(),
                'first' => $paginator->onFirstPage(),
                'last' => ! $paginator->hasMorePages(),
                'hasNext' => $paginator->hasMorePages(),
                'hasPrevious' => ! $paginator->onFirstPage(),
            ],
        ], 200);
    }

    /**
     * Lấy danh sách chuyên môn của giảng viên
     */
    public function layDanhSachChuyenMon(Request $request)
    {
        $specializations = GiangVien::whereNotNull('chuyen_mon')
            ->where('chuyen_mon', '<>', '')
            ->distinct()
            ->pluck('chuyen_mon')
            ->values()
            ->all();

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => $specializations,
            ],
        ], 200);
    }

    /**
     * Import sinh viên từ file Excel
     */
    public function importStudents(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
            'className' => 'required|string|exists:lop,ten_lop',
        ], [
            'file.required' => 'Vui lòng tải lên file Excel.',
            'file.mimes' => 'File tải lên phải là định dạng Excel (.xlsx, .xls).',
            'className.required' => 'Vui lòng chọn lớp học trước khi import.',
            'className.exists' => 'Lớp học được chọn không tồn tại trong hệ thống.',
        ]);

        $className = $request->input('className');
        $lop = Lop::where('ten_lop', trim($className))->first();
        if (! $lop) {
            return response()->json([
                'success' => false,
                'message' => 'Lớp học không tồn tại.',
            ], 400);
        }

        $file = $request->file('file');

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            $importedCount = 0;
            $errors = [];

            foreach ($rows as $index => $row) {
                if ($index === 0) {
                    continue;
                } // Bỏ qua dòng tiêu đề

                $mssv = trim($row[0] ?? '');
                $hoTen = trim($row[1] ?? '');
                $ngaySinhRaw = trim($row[2] ?? '');
                $soDienThoai = trim($row[3] ?? '');
                $emailRaw = trim($row[4] ?? '');

                if (empty($mssv) && empty($hoTen)) {
                    continue;
                }

                if (empty($mssv) || ! preg_match('/^0[0-9]+$/', $mssv) || strlen($mssv) > 10) {
                    $errors[] = 'Dòng '.($index + 1).": MSSV '$mssv' không hợp lệ (phải bắt đầu bằng số 0 và dài tối đa 10 số).";

                    continue;
                }

                if (SinhVien::where('ma_so_sinh_vien', $mssv)->exists()) {
                    $errors[] = 'Dòng '.($index + 1).": MSSV '$mssv' đã tồn tại trong hệ thống.";

                    continue;
                }

                if (empty($hoTen)) {
                    $errors[] = 'Dòng '.($index + 1).': Họ tên không được để trống.';

                    continue;
                }

                if (empty($emailRaw)) {
                    $email = $mssv.'@caothang.edu.vn';
                } else {
                    $email = $emailRaw;
                    if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                        $errors[] = 'Dòng '.($index + 1).": Email '$email' không đúng định dạng.";

                        continue;
                    }
                }

                if (SinhVien::where('email', $email)->exists()) {
                    $errors[] = 'Dòng '.($index + 1).": Email '$email' đã được đăng ký.";

                    continue;
                }

                $gioiTinh = null;

                $ngaySinh = null;
                if (! empty($ngaySinhRaw)) {
                    $date = null;
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ngaySinhRaw)) {
                        $date = \DateTime::createFromFormat('Y-m-d', $ngaySinhRaw);
                    } elseif (preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $ngaySinhRaw)) {
                        $date = \DateTime::createFromFormat('d/m/Y', $ngaySinhRaw);
                    }

                    if ($date) {
                        $today = new \DateTime;
                        $today->setTime(0, 0, 0, 0);
                        if ($date > $today) {
                            $errors[] = 'Dòng '.($index + 1).': Ngày sinh không được là ngày trong tương lai.';

                            continue;
                        }
                        $ngaySinh = $date->format('Y-m-d');
                    } else {
                        $errors[] = 'Dòng '.($index + 1).": Ngày sinh '$ngaySinhRaw' không đúng định dạng (YYYY-MM-DD hoặc DD/MM/YYYY).";

                        continue;
                    }
                }

                $sv = SinhVien::create([
                    'ma_so_sinh_vien' => $mssv,
                    'ho_ten' => $hoTen,
                    'email' => $email,
                    'so_dien_thoai' => $soDienThoai ?: null,
                    'gioi_tinh' => $gioiTinh,
                    'ngay_sinh' => $ngaySinh,
                    'lop_id' => $lop->lop_id,
                    'dang_hoat_dong' => 1,
                ]);

                RealtimeService::broadcast('slot_updated', [
                    'type' => 'user_created',
                    'role' => 'student',
                    'payload' => [
                        'id' => (string) $sv->ma_so_sinh_vien,
                        'name' => $sv->ho_ten,
                        'email' => $sv->email,
                        'className' => $lop->ten_lop,
                        'phone' => $sv->so_dien_thoai,
                        'role' => 'student',
                        'status' => 'active',
                        'gender' => $sv->gioi_tinh,
                        'dateOfBirth' => $sv->ngay_sinh,
                    ],
                ]);

                $importedCount++;
            }

            if (count($errors) > 0 && $importedCount === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Import thất bại. Vui lòng kiểm tra lại dữ liệu.',
                    'errors' => $errors,
                ], 422);
            }

            return response()->json([
                'success' => true,
                'message' => "Import thành công $importedCount sinh viên vào lớp ".$lop->ten_lop.'.',
                'imported_count' => $importedCount,
                'errors' => $errors,
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Lỗi xử lý file Excel: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Xem chi tiết người dùng bằng ID (MSSV hoặc mã GV)
     */
    public function xemChiTiet(Request $request, $id)
    {
        $role = $request->query('role');

        if ($role === 'teacher' || $role === 'admin') {
            $gv = GiangVien::where('giang_vien_id', $id)->first();
            if ($gv) {
                return response()->json([
                    'code' => 200,
                    'results' => [
                        'object' => $this->transformTeacher($gv),
                    ],
                ], 200);
            }
        } elseif ($role === 'student') {
            $sv = SinhVien::where('ma_so_sinh_vien', $id)
                ->orWhere('sinh_vien_id', $id)
                ->first();
            if ($sv) {
                return response()->json([
                    'code' => 200,
                    'results' => [
                        'object' => $this->transformStudent($sv),
                    ],
                ], 200);
            }
        } else {
            // Fallback nếu không truyền role
            $sv = SinhVien::where('ma_so_sinh_vien', $id)
                ->orWhere('sinh_vien_id', $id)
                ->first();
            if ($sv) {
                return response()->json([
                    'code' => 200,
                    'results' => [
                        'object' => $this->transformStudent($sv),
                    ],
                ], 200);
            }

            $gv = GiangVien::where('giang_vien_id', $id)->first();
            if ($gv) {
                return response()->json([
                    'code' => 200,
                    'results' => [
                        'object' => $this->transformTeacher($gv),
                    ],
                ], 200);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Không tìm thấy người dùng với ID này!',
        ], 404);
    }

    public function themMoi(ThemNguoiDungRequest $request)
    {
        $role = $request->input('role', 'student');
        $data = $request->toServiceData();

        if ($role === 'teacher' || $role === 'admin') {
            $gv = $this->nguoiDungService->themGiangVien($data);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformTeacher($gv),
                ],
            ], 200);
        } else {
            $sv = $this->nguoiDungService->themSinhVien($data);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformStudent($sv),
                ],
            ], 200);
        }
    }

    /**
     * Cập nhật thông tin người dùng
     */
    public function capNhat(CapNhatNguoiDungRequest $request, $id)
    {
        $role = $request->query('role') ?? $request->input('role');
        $data = $request->toServiceData();

        $isTeacher = false;
        if ($role === 'teacher' || $role === 'admin') {
            $isTeacher = true;
        } elseif ($role === 'student') {
            $isTeacher = false;
        } else {
            // Fallback nếu không truyền role
            $isTeacher = GiangVien::where('giang_vien_id', $id)->exists();
        }

        if ($isTeacher) {
            $gv = $this->nguoiDungService->capNhatGiangVien($id, $data);

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $this->transformTeacher($gv),
                ],
            ], 200);
        } else {
            $sv = SinhVien::where('ma_so_sinh_vien', $id)
                ->orWhere('sinh_vien_id', $id)
                ->first();

            if ($sv) {
                $sv = $this->nguoiDungService->capNhatSinhVien($sv->sinh_vien_id, $data);

                return response()->json([
                    'code' => 200,
                    'results' => [
                        'object' => $this->transformStudent($sv),
                    ],
                ], 200);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Không tìm thấy người dùng với ID này!',
        ], 404);
    }

    /**
     * Khóa tài khoản người dùng (Xử lý cho API DELETE phía Frontend)
     */
    public function xoaNguoiDung(Request $request, $id)
    {
        $role = $request->query('role') ?? $request->input('role');

        if ($role === 'teacher' || $role === 'admin') {
            $gv = GiangVien::where('giang_vien_id', $id)->first();
            if ($gv) {
                $this->nguoiDungService->doiTrangThaiGiangVien($gv->giang_vien_id, 0);
                return response()->json([
                    'success' => true,
                    'message' => 'Khóa tài khoản giảng viên thành công!',
                ], 200);
            }
        } elseif ($role === 'student') {
            $sv = SinhVien::where('ma_so_sinh_vien', $id)
                ->orWhere('sinh_vien_id', $id)
                ->first();
            if ($sv) {
                $this->nguoiDungService->doiTrangThaiSinhVien($sv->sinh_vien_id, 0);
                return response()->json([
                    'success' => true,
                    'message' => 'Khóa tài khoản sinh viên thành công!',
                ], 200);
            }
        } else {
            // Fallback nếu không truyền role
            $sv = SinhVien::where('ma_so_sinh_vien', $id)
                ->orWhere('sinh_vien_id', $id)
                ->first();
            if ($sv) {
                $this->nguoiDungService->doiTrangThaiSinhVien($sv->sinh_vien_id, 0);
                return response()->json([
                    'success' => true,
                    'message' => 'Khóa tài khoản sinh viên thành công!',
                ], 200);
            }

            $gv = GiangVien::where('giang_vien_id', $id)->first();
            if ($gv) {
                $this->nguoiDungService->doiTrangThaiGiangVien($gv->giang_vien_id, 0);
                return response()->json([
                    'success' => true,
                    'message' => 'Khóa tài khoản giảng viên thành công!',
                ], 200);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Không tìm thấy người dùng!',
        ], 404);
    }

    // Helper functions
    private function transformStudent($sv)
    {
        return [
            'id' => (string) $sv->ma_so_sinh_vien,
            'name' => $sv->ho_ten,
            'email' => $sv->email,
            'className' => $sv->lop ? $sv->lop->ten_lop : null,
            'phone' => $sv->so_dien_thoai,
            'role' => 'student',
            'status' => $sv->dang_hoat_dong == 1 ? 'active' : 'inactive',
            'gender' => $sv->gioi_tinh,
            'dateOfBirth' => $sv->ngay_sinh,
        ];
    }

    private function transformTeacher($gv)
    {
        return [
            'id' => (string) $gv->giang_vien_id,
            'name' => $gv->ho_ten,
            'email' => $gv->email,
            'className' => $gv->chuyen_mon,
            'phone' => $gv->so_dien_thoai,
            'role' => strtolower($gv->vai_tro) === 'admin' ? 'admin' : 'teacher',
            'status' => $gv->dang_hoat_dong == 1 ? 'active' : 'inactive',
            'academicDegree' => $gv->hoc_vi,
            'specialization' => $gv->chuyen_mon,
        ];
    }
}
