<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\DiemSinhVienService;
use App\Http\Requests\Admin\CapNhatDiemSinhVienRequest;

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
        $filters = [
            'periodId' => $request->input('periodId'),
            'mode' => $request->input('mode', 'internship'),
            'keyword' => $request->input('keyword'),
            'className' => $request->input('className'),
            'status' => $request->input('status')
        ];

        $res = $this->diemSinhVienService->getScoresList($filters);

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
     * API Xem chi tiết điểm tốt nghiệp/thực tập của 1 sinh viên
     */
    public function xemChiTiet(Request $request, $id)
    {
        $mode = $request->input('mode', 'internship');
        $score = $this->diemSinhVienService->getScoreDetail($id, $mode);

        if (!$score) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy điểm số của sinh viên này!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $score
            ]
        ], 200);
    }

    /**
     * API Cập nhật điểm tốt nghiệp/thực tập của 1 sinh viên
     */
    public function capNhat(CapNhatDiemSinhVienRequest $request, $id)
    {

        $score = $this->diemSinhVienService->updateScore($id, $request->all());

        if (!$score) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy thông tin hoặc đợt tốt nghiệp tương ứng để chấm điểm!'
            ], 404);
        }

        \App\Services\RealtimeService::broadcast('score_updated', [
            'type' => 'score_updated',
            'studentId' => $id,
            'payload' => $score
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $score
            ]
        ], 200);
    }
}
