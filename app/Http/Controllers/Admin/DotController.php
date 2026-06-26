<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\DotService;
use App\Http\Requests\Admin\QuanLyDot\ThemDotRequest;

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
            'status' => $request->input('status')
        ];

        $res = $this->dotService->getListPeriod($filters, $limit);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total']
                ]
            ],
            'pagination' => [
                'total' => $res['total'],
                'totalPages' => $res['lastPage'],
                'limit' => $res['perPage'],
                'first' => $res['onFirstPage'],
                'last' => !$res['hasMorePages'],
                'hasNext' => $res['hasMorePages'],
                'hasPrevious' => !$res['onFirstPage']
            ]
        ], 200);
    }

    /**
     * API Xem chi tiết đợt tốt nghiệp
     */
    public function xemChiTiet(Request $request, $id)
    {
        $period = $this->dotService->getPeriodDetail($id);
        if (!$period) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt đăng ký này!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $period
            ]
        ], 200);
    }

    /**
     * API Tạo mới đợt tốt nghiệp
     */
    public function themMoi(ThemDotRequest $request)
    {

        $period = $this->dotService->createPeriod($request->all());

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $period
            ]
        ], 200);
    }

    /**
     * API Cập nhật đợt tốt nghiệp
     */
    public function capNhat(Request $request, $id)
    {
        $period = $this->dotService->updatePeriod($id, $request->all());
        if (!$period) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt đăng ký này để cập nhật!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $period
            ]
        ], 200);
    }

    /**
     * API Xóa đợt tốt nghiệp
     */
    public function xoa(Request $request, $id)
    {
        $success = $this->dotService->deletePeriod($id);
        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đợt đăng ký này để xóa!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Xóa đợt đăng ký tốt nghiệp thành công!'
        ], 200);
    }
}
