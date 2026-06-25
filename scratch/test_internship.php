<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\SinhVien;
use Illuminate\Http\Request;
use App\Http\Controllers\SinhVien\ThucTapController;

$student = SinhVien::first();
if (!$student) {
    echo "No student found in database to test!\n";
    exit(1);
}

echo "Testing internship endpoints for student: {$student->ho_ten} ({$student->ma_so_sinh_vien})\n";

$controller = app(ThucTapController::class);

// 1. Test Listing Companies
echo "\n--- 1. Testing GET /private/v1/student/companies ---\n";
$reqList = Request::create('/api/private/v1/student/companies', 'GET');
$reqList->setUserResolver(fn() => $student);
$resList = $controller->layDanhSachCongTy($reqList);
echo "Status: " . $resList->getStatusCode() . "\n";
echo "Companies Count: " . count(json_decode($resList->getContent())->results->objects) . "\n";

// 2. Test Fetching My Request
echo "\n--- 2. Testing GET /private/v1/student/internships/my-request ---\n";
$reqMy = Request::create('/api/private/v1/student/internships/my-request', 'GET');
$reqMy->setUserResolver(fn() => $student);
$resMy = $controller->xemYeuCauCuaToi($reqMy);
echo "Status: " . $resMy->getStatusCode() . "\n";
echo "Body: " . $resMy->getContent() . "\n";

// 3. Test Declare Internship
echo "\n--- 3. Testing POST /private/v1/student/internships/declare ---\n";
$reqDecl = Request::create('/api/private/v1/student/internships/declare', 'POST', [
    'companyName' => 'Công ty Cổ phần Công nghệ Hoàng Long',
    'field' => 'Thiết kế Vi mạch',
    'address' => 'Khu Công nghệ cao, Quận 9, TP.HCM',
    'mentor' => 'Ông Hoàng Văn Long',
    'phone' => '0933445566',
    'email' => 'contact@hoanglongtech.com',
    'duration' => '10 tuần',
    'confirmPaper' => true,
    'internshipAddress' => 'Tầng 5, Tòa nhà HL, Q9'
]);
$reqDecl->setUserResolver(fn() => $student);
$resDecl = $controller->khaiBaoThucTap($reqDecl);
echo "Status: " . $resDecl->getStatusCode() . "\n";
echo "Body: " . $resDecl->getContent() . "\n";

// 4. Test Fetching My Request again after declaration
echo "\n--- 4. Testing GET /private/v1/student/internships/my-request (after declare) ---\n";
$resMy2 = $controller->xemYeuCauCuaToi($reqMy);
echo "Status: " . $resMy2->getStatusCode() . "\n";
echo "Body: " . $resMy2->getContent() . "\n";
