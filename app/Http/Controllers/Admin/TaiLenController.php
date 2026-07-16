<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class TaiLenController extends Controller
{
    public function upload(Request $request)
    {
        if (! $request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy file tải lên!',
            ], 400);
        }

        $file = $request->file('file');

        // Cấu hình disk từ env hoặc mặc định là public
        $disk = env('FILESYSTEM_DISK', 'public');
        if ($disk === 'local') {
            $disk = 'public'; // Tránh dùng local private disk gây lỗi URL không truy cập được
        }

        // Tạo tên file độc nhất tránh đè dữ liệu
        $filename = time().'_'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->getClientOriginalExtension();

        // Lưu file vào disk
        $path = $file->storeAs('uploads', $filename, $disk);

        // Lấy URL công khai
        $fileUrl = Storage::disk($disk)->url($path);

        return response()->json([
            'cloudFrontUrl' => $fileUrl,
            's3Url' => $fileUrl,
            'mimetype' => $file->getClientMimeType(),
            'key' => $path,
            'originalName' => $file->getClientOriginalName(),
            'size' => Storage::disk($disk)->size($path),
        ], 200);
    }
}
