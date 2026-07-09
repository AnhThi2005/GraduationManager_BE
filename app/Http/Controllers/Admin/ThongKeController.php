<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ThongKeService;
use Illuminate\Http\Request;

class ThongKeController extends Controller
{
    protected $thongKeService;

    public function __construct(ThongKeService $thongKeService)
    {
        $this->thongKeService = $thongKeService;
    }

    /**
     * API Lấy dữ liệu thống kê tổng hợp hiển thị trên Dashboard Admin
     */
    public function getDashboardData(Request $request)
    {
        try {
            $data = $this->thongKeService->getDashboardData();

            return response()->json([
                'code' => 200,
                'results' => [
                    'object' => $data,
                ],
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Có lỗi xảy ra khi lấy dữ liệu thống kê: '.$e->getMessage(),
            ], 500);
        }
    }
}
