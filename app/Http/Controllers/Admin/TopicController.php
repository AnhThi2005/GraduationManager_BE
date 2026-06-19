<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Services\TopicService;

class TopicController extends Controller
{
    protected $topicService;

    public function __construct(TopicService $topicService)
    {
        $this->topicService = $topicService;
    }

    /**
     * API Lấy danh sách đề tài (Có phân trang & lọc)
     */
    public function layDanhSach(Request $request)
    {
        $limit = $request->input('limit', 10);
        $filters = [
            'keyword' => $request->input('keyword'),
            'status' => $request->input('status'),
            'periodId' => $request->input('periodId')
        ];

        $res = $this->topicService->getListTopic($filters, $limit);

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
     * API Xem chi tiết đề tài
     */
    public function xemChiTiet(Request $request, $id)
    {
        $topic = $this->topicService->getTopicDetail($id);
        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này!'
            ], 404);
        }

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic
            ]
        ], 200);
    }

    /**
     * API Tạo mới đề tài
     */
    public function themMoi(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'teacher' => 'required|string',
            'slots' => 'required|string',
            'status' => 'sometimes|string|in:pending,approved,rejected',
            'rejectReason' => 'required_if:status,rejected|nullable|string|max:1000',
        ], [
            'rejectReason.required_if' => 'Lý do từ chối là bắt buộc khi chuyển trạng thái đề tài sang Từ chối!',
        ]);

        $periodId = $request->query('periodId');

        $topic = $this->topicService->createTopic($request->all(), $periodId);

        \App\Services\RealtimeService::broadcast('notification', [
            'title' => 'Đề tài mới được đề xuất',
            'message' => 'Giảng viên ' . ($topic['teacher'] ?? 'GV') . ' vừa đề xuất đề tài: ' . ($topic['name'] ?? ''),
            'type' => 'topic_proposed',
            'payload' => $topic
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic
            ]
        ], 200);
    }

    /**
     * API Cập nhật đề tài
     */
    public function capNhat(Request $request, $id)
    {
        $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'teacher' => 'sometimes|required|string',
            'slots' => 'sometimes|required|string',
            'status' => 'sometimes|string|in:pending,approved,rejected',
            'rejectReason' => 'required_if:status,rejected|nullable|string|max:1000',
        ], [
            'rejectReason.required_if' => 'Lý do từ chối là bắt buộc khi chuyển trạng thái đề tài sang Từ chối!',
        ]);

        $topic = $this->topicService->updateTopic($id, $request->all());
        if (!$topic) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để cập nhật!'
            ], 404);
        }

        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'topic_updated',
            'topicId' => $id,
            'payload' => $topic
        ]);

        return response()->json([
            'code' => 200,
            'results' => [
                'object' => $topic
            ]
        ], 200);
    }

    /**
     * API Xóa đề tài
     */
    public function xoa(Request $request, $id)
    {
        $success = $this->topicService->deleteTopic($id);
        if (!$success) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy đề tài này để xóa!'
            ], 404);
        }

        \App\Services\RealtimeService::broadcast('slot_updated', [
            'type' => 'topic_deleted',
            'topicId' => $id
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Xóa đề tài thành công!'
        ], 200);
    }
}
