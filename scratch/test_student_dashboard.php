<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SinhVien;
use Illuminate\Http\Request;
use App\Http\Controllers\SinhVien\TrangChuController;

// Let's find a student to simulate the logged-in user
$student = SinhVien::first();
if (!$student) {
    echo "No student found in database to test!\n";
    exit(1);
}

echo "Testing student dashboard for student: {$student->ho_ten} ({$student->ma_so_sinh_vien})\n";

// Act as the student
$request = Request::create('/api/private/v1/student/dashboard', 'GET');
$request->setUserResolver(function () use ($student) {
    return $student;
});

try {
    $controller = app(TrangChuController::class);
    $response = $controller->layThongTinTrangChu($request);
    
    echo "Status: " . $response->getStatusCode() . "\n";
    echo "Body: " . json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Exception $e) {
    echo "Error occurred: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
