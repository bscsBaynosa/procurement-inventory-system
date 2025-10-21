<?php

use Illuminate\Support\Facades\Route;
use App\Controllers\AuthController;
use App\Controllers\CustodianController;
use App\Controllers\ProcurementController;

// Landing page route
Route::get('/', function () {
    return view('auth.login');
});

// Authentication routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout']);

// Custodian routes
Route::middleware(['auth'])->group(function () {
    Route::get('/custodian/dashboard', [CustodianController::class, 'dashboard']);
    Route::get('/custodian/inventory', [CustodianController::class, 'index']);
    Route::post('/custodian/inventory/add', [CustodianController::class, 'addItem']);
    Route::post('/custodian/inventory/update/{id}', [CustodianController::class, 'updateItem']);
    Route::post('/custodian/request/purchase', [CustodianController::class, 'createPurchaseRequest']);
    Route::get('/custodian/request/status', [CustodianController::class, 'requestStatus']);
    Route::get('/custodian/report', [CustodianController::class, 'generateReport']);
});

// Procurement manager routes
Route::middleware(['auth', 'isManager'])->group(function () {
    Route::get('/manager/dashboard', [ProcurementController::class, 'dashboard']);
    Route::get('/manager/requests', [ProcurementController::class, 'viewRequests']);
    Route::post('/manager/request/update/{id}', [ProcurementController::class, 'updateRequest']);
    Route::post('/manager/request/followup/{id}', [ProcurementController::class, 'followUpRequest']);
});