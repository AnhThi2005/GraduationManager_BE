<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\DiemSinhVienService;
use Illuminate\Http\Request;

class DiemSinhVienController extends Controller
{
    protected $diemSinhVienService;

    public function __construct(DiemSinhVienService $diemSinhVienService)
    {
        $this->diemSinhVienService = $diemSinhVienService;
    }

    /**
     * API Lấy danh sách điểm tốt nghiệp/thực tập của sinh viên
     */
    public function layDanhSach(Request $request)
    {
        $limit = $request->input('limit', 10);
        $page = $request->input('page', 1);

        $filters = [
            'periodId' => $request->input('periodId'),
            'mode' => $request->input('mode', 'internship'),
            'keyword' => $request->input('keyword'),
            'className' => $request->input('className'),
            'status' => $request->input('status'),
        ];

        $res = $this->diemSinhVienService->getScoresList($filters, $limit, $page);

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
     * API Xem chi tiết điểm tốt nghiệp/thực tập của 1 sinh viên
     */
    public function xemChiTiet(Request $request, $id)
    {
        $mode = $request->input('mode', 'internship');
        $score = $this->diemSinhVienService->getScoreDetail($id, $mode);

        if (! $score) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy điểm số của sinh viên này!',
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $score,
            ],
        ], 200);
    }
}
