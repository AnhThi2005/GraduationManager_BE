<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\QuanLyDot\ThemDotRequest;
use App\Services\DotService;
use Illuminate\Http\Request;

class DotController extends Controller
{
    protected $dotService;

    public function __construct(DotService $dotService)
    {
        $this->dotService = $dotService;
    }

    /**
     * API Lấy danh sách đợt tốt nghiệp (Phân trang & Lọc)
     */
    public function layDanhSach(Request $request)
    {
        $limit = $request->input('limit', 10);
        $filters = [
            'keyword' => $request->input('keyword'),
            'type' => $request->input('type'),
            'status' => $request->input('status'),
        ];

        $res = $this->dotService->getListPeriod($filters, $limit);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total'],
                ],
            ],
            'pagination' => [
                'total' => $res['total'],
                'totalPages' => $res['lastPage'],
                'limit' => $res['perPage'],
                'first' => $res['onFirstPage'],
                'last' => ! $res['hasMorePages'],
                'hasNext' => $res['hasMorePages'],
                'hasPrevious' => ! $res['onFirstPage'],
            ],
        ], 200);
    }

    /**
     * API Xem chi tiết đợt tốt nghiệp
     */
    public function xemChiTiet(Request $request, $id)
    {
        $period = $this->dotService->getPeriodDetail($id);
        if (! $period) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt đăng ký này!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $period,
            ],
        ], 200);
    }

    /**
     * API Tạo mới đợt tốt nghiệp
     */
    public function themMoi(ThemDotRequest $request)
    {
        try {
            $period = $this->dotService->createPeriod($request->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $period,
            ],
        ], 200);
    }

    /**
     * API Cập nhật đợt tốt nghiệp
     */
    public function capNhat(Request $request, $id)
    {
        try {
            $period = $this->dotService->updatePeriod($id, $request->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        if (! $period) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt đăng ký này để cập nhật!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $period,
            ],
        ], 200);
    }

    /**
     * API Xóa đợt tốt nghiệp
     */
    public function xoa(Request $request, $id)
    {
        try {
            $success = $this->dotService->deletePeriod($id);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        if (! $success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt đăng ký này để xóa!',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Xóa đợt đăng ký tốt nghiệp thành công!',
        ], 200);
    }

    /**
     * API Thêm sinh viên tự do/rớt vào các đợt tốt nghiệp
     */
    public function themSinhVienVaoCacDot(Request $request)
    {
        $request->validate([
            'studentId' => 'required',
            'periodIds' => 'required|array',
            'periodIds.*' => 'required',
        ]);

        $studentId = $request->input('studentId');
        $periodIds = $request->input('periodIds');

        try {
            $res = $this->dotService->addStudentToPeriods($studentId, $periodIds);
            if (! $res) {
                return response()->json([
                    'success' => false,
                    'message' => 'Không tìm thấy sinh viên hoặc đợt hợp lệ!',
                ], 400);
            }
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'message' => 'Thêm sinh viên vào các đợt thành công!',
        ], 200);
    }
}
