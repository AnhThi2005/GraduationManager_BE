<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Controllers\Admin\TopicController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Get an existing topic ID to test update
$topic = \App\Models\DeTai::first();
if (!$topic) {
    echo "No topic found in database to test!\n";
    exit(1);
}
$topicId = $topic->de_tai_id;
echo "Testing with Topic ID: {$topicId}\n";

// Case 1: Change status to rejected without rejectReason (Should fail validation)
echo "\n--- Case 1: Update status to 'rejected' without reason ---\n";
try {
    $request = Request::create("/private/v1/topics/{$topicId}", 'PATCH', [
        'status' => 'rejected',
        'name' => 'Test Topic Rejection Validation',
        'teacher' => 'TS. Test',
        'slots' => '0/3'
    ]);
    
    $controller = app(TopicController::class);
    $response = $controller->capNhat($request, $topicId);
    echo "Status code: " . $response->getStatusCode() . "\n";
    echo "Body: " . $response->getContent() . "\n";
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "Validation failed successfully! Errors:\n";
    print_r($e->errors());
}

// Case 2: Change status to rejected WITH rejectReason (Should pass)
echo "\n--- Case 2: Update status to 'rejected' with reason ---\n";
try {
    $request = Request::create("/private/v1/topics/{$topicId}", 'PATCH', [
        'status' => 'rejected',
        'rejectReason' => 'Topic scope is too broad and lacks academic contribution.',
        'name' => 'Test Topic Rejection Validation',
        'teacher' => 'TS. Test',
        'slots' => '0/3'
    ]);
    
    $controller = app(TopicController::class);
    $response = $controller->capNhat($request, $topicId);
    echo "Status code: " . $response->getStatusCode() . "\n";
    echo "Body: " . $response->getContent() . "\n";
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "Validation failed unexpectedly! Errors:\n";
    print_r($e->errors());
}

// Case 3: Change status back to approved (Should clear rejectReason in database)
echo "\n--- Case 3: Change status back to 'approved' ---\n";
try {
    $request = Request::create("/private/v1/topics/{$topicId}", 'PATCH', [
        'status' => 'approved',
        'name' => 'Test Topic Rejection Validation',
        'teacher' => 'TS. Test',
        'slots' => '0/3'
    ]);
    
    $controller = app(TopicController::class);
    $response = $controller->capNhat($request, $topicId);
    echo "Status code: " . $response->getStatusCode() . "\n";
    
    // Check in database
    $updatedTopic = \App\Models\DeTai::find($topicId);
    echo "Trang thai in DB: {$updatedTopic->trang_thai}\n";
    echo "Ly do tu choi in DB: " . (is_null($updatedTopic->ly_do_tu_choi) ? 'NULL' : $updatedTopic->ly_do_tu_choi) . "\n";
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "Validation failed unexpectedly! Errors:\n";
    print_r($e->errors());
}
