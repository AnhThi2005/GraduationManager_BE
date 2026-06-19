<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\StudentScoreService;

class StudentScoreController extends Controller
{
    protected $studentScoreService;

    public function __construct(StudentScoreService $studentScoreService)
    {
        $this->studentScoreService = $studentScoreService;
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

        $res = $this->studentScoreService->getScoresList($filters);

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
        $score = $this->studentScoreService->getScoreDetail($id, $mode);

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
    public function capNhat(Request $request, $id)
    {
        $request->validate([
            'status' => 'sometimes|string|in:draft,reviewing,finalized',
            'finalScore' => 'sometimes|numeric|min:0|max:10',
            'defenseScore' => 'sometimes|numeric|min:0|max:3',
            'demoScore' => 'sometimes|numeric|min:0|max:5',
            'qaScore' => 'sometimes|numeric|min:0|max:2',
            'reportScore' => 'sometimes|numeric|min:0|max:10',
            'mode' => 'required|string|in:internship,project',
            'dot_id' => 'sometimes|integer'
        ]);

        $score = $this->studentScoreService->updateScore($id, $request->all());

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
