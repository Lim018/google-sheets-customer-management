<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('auth.login');
});

// Webhook endpoint (tidak perlu authentication)
// Route::post('/api/spreadsheet-update', [WebhookController::class, 'handleSpreadsheetUpdate'])
//     ->name('webhook.spreadsheet-update');

Route::middleware('auth')->group(function () {
    // Profile routes
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    
    // Dashboard routes
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/followup-today', [DashboardController::class, 'followupToday'])->name('followup.today');
    Route::get('/dashboard/archived', [DashboardController::class, 'archived'])->name('dashboard.archived');
    
    // Customer management routes
    Route::patch('/dashboard/customer/{customer}', [DashboardController::class, 'updateCustomer'])
        ->name('customer.update');
    Route::patch('/customer/{customer}/mark-completed', [DashboardController::class, 'markCompleted'])
        ->name('customer.mark-completed');
    Route::patch('/customer/{customer}/archive', [DashboardController::class, 'archiveCustomer'])
        ->name('customer.archive');
    Route::patch('/customer/{customer}/restore', [DashboardController::class, 'restoreCustomer'])
        ->name('customer.restore');
    
    // Admin only routes
    Route::middleware('admin')->group(function () {
        Route::get('/admin/activity-logs', [ActivityLogController::class, 'index'])
            ->name('admin.activity-logs');
        Route::get('/admin/customer/{customer}/logs', [ActivityLogController::class, 'customerLogs'])
            ->name('admin.customer-logs');
    });
});

require __DIR__.'/auth.php';