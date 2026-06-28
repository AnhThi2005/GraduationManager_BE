<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\LopService;
use App\Http\Requests\Admin\ThemLopRequest;

class LopController extends Controller
{
    protected $lopService;

    public function __construct(LopService $lopService)
    {
        $this->lopService = $lopService;
    }

    /**
     * API Lấy danh sách lớp học
     */
    public function layDanhSach(Request $request)
    {
        $periodId = $request->input('periodId') ?? $request->input('period_id');
        $res = $this->lopService->getListClass($periodId);

        return response()->json([
            'code' => 200,
            'results' => [
                'objects' => [
                    'rows' => $res['rows'],
                    'total' => $res['total']
                ]
            ]
        ], 200);
    }

    /**
     * API Xem chi tiết lớp học
     */
    public function xemChiTiet(Request $request, $id)
    {
        $class = $this->lopService->getClassDetail($id);
        if (!$class) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy lớp học này!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $class
            ]
        ], 200);
    }

    /**
     * API Tạo mới lớp học
     */
    public function themMoi(ThemLopRequest $request)
    {
        $class = $this->lopService->createClass($request->all());

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $class
            ]
        ], 200);
    }

    /**
     * API Cập nhật lớp học
     */
    public function capNhat(Request $request, $id)
    {
        $class = $this->lopService->updateClass($id, $request->all());
        if (!$class) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy lớp học này để cập nhật!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $class
            ]
        ], 200);
    }

    /**
     * API Xóa lớp học
     */
    public function xoa(Request $request, $id)
    {
        $success = $this->lopService->deleteClass($id);
        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy lớp học này để xóa!'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Xóa lớp học thành công!'
        ], 200);
    }
}
