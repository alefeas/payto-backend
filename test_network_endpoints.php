<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use App\Http\Controllers\Api\CompanyConnectionController;
use App\Models\Company;
use Illuminate\Http\Request;

// Bootstrap Laravel
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

try {
    // Get a company ID for testing
    $company = Company::first();
    if (!$company) {
        echo "No companies found in database\n";
        exit(1);
    }
    
    echo "Testing with company ID: " . $company->id . "\n";
    
    // Create controller instance
    $controller = new CompanyConnectionController();
    
    // Test stats method
    echo "Testing stats method...\n";
    $statsResponse = $controller->stats($company->id);
    echo "Stats response: " . $statsResponse->getContent() . "\n";
    
    // Test sentRequests method
    echo "Testing sentRequests method...\n";
    $sentResponse = $controller->sentRequests($company->id);
    echo "Sent requests response: " . $sentResponse->getContent() . "\n";
    
    echo "All tests passed!\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}