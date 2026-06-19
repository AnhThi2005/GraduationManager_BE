<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\ClassService;

class ClassController extends Controller
{
    protected $classService;

    public function __construct(ClassService $classService)
    {
        $this->classService = $classService;
    }

    /**
     * API Lấy danh sách lớp học
     */
    public function layDanhSach(Request $request)
    {
        $res = $this->classService->getListClass();

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
        $class = $this->classService->getClassDetail($id);
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
    public function themMoi(Request $request)
    {
        $request->validate([
            'code' => 'required|string|max:255',
            'name' => 'required|string|max:255',
        ]);

        $class = $this->classService->createClass($request->all());

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
        $class = $this->classService->updateClass($id, $request->all());
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
        $success = $this->classService->deleteClass($id);
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
