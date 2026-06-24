<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        if (!$request->hasFile('file')) {
            return response()->json([
                'success' => false,
                'message' => 'Không tìm thấy file tải lên!'
            ], 400);
        }

        $file = $request->file('file');
        
        // Tạo tên file độc nhất tránh đè dữ liệu
        $filename = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
        
        // Lưu file vào thư mục public/uploads
        $file->move(public_path('uploads'), $filename);
        
        $fileUrl = asset('uploads/' . $filename);

        return response()->json([
            'cloudFrontUrl' => $fileUrl,
            's3Url' => $fileUrl,
            'mimetype' => $file->getClientMimeType(),
            'key' => 'uploads/' . $filename,
            'originalName' => $file->getClientOriginalName(),
            'size' => file_exists(public_path('uploads/' . $filename)) ? filesize(public_path('uploads/' . $filename)) : 0
        ], 200);
    }
}
