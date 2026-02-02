<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\RouterController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes - Skynet E-Billing
|--------------------------------------------------------------------------
|
| Routes organized by feature:
| - Dashboard (Accounting widgets)
| - Customer Management
| - Invoice Management
| - Payment Entry
| - Package Management
|
*/

// Redirect root to login
Route::get('/', function () {
    return redirect()->route('login');
});

// =====================================================
// Public Payment Routes
// =====================================================
Route::get('/pay/{uuid}', [\App\Http\Controllers\PublicInvoiceController::class, 'show'])->name('public.invoice.show');
Route::post('/pay/{uuid}', [\App\Http\Controllers\PublicInvoiceController::class, 'pay'])->name('public.invoice.pay');
Route::post('/callback/tripay', [\App\Http\Controllers\TripayCallbackController::class, 'handle'])->name('callback.tripay');


// Authenticated Routes
Route::middleware(['auth', 'verified'])->group(function () {
    
    // =====================================================
    // Dashboard - Enhanced with Accounting Widgets
    // =====================================================
    Route::get('/dashboard', [DashboardController::class, 'index'])
        ->name('dashboard');
    
    // =====================================================
    // Profile Management
    // =====================================================
    Route::get('/profile', [ProfileController::class, 'edit'])
        ->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])
        ->name('profile.destroy');
    
    // =====================================================
    // Customer Management
    // =====================================================
    Route::resource('customers', CustomerController::class);
    Route::post('/customers/{customer}/isolate', [CustomerController::class, 'isolate'])->name('customers.isolate');
    Route::post('/customers/{customer}/reconnect', [CustomerController::class, 'reconnect'])->name('customers.reconnect');
    
    // =====================================================
    // Package Management
    // =====================================================
    Route::resource('customers', CustomerController::class);
    Route::resource('routers', RouterController::class);
    Route::post('/routers/sync-all', [RouterController::class, 'syncAll'])->name('routers.sync-all');
    Route::post('/routers/{router}/test-connection', [RouterController::class, 'testConnection'])->name('routers.test');
    Route::post('/routers/{router}/scan', [RouterController::class, 'scanRouter'])->name('routers.scan');
    Route::post('/routers/{router}/sync', [RouterController::class, 'sync'])->name('routers.sync'); // Unified Sync Route
    Route::get('/api/routers/{router}/customers', [RouterController::class, 'customers'])->name('routers.customers');
    Route::get('/api/routers/{router}/live-stats', [\App\Http\Controllers\Api\RouterStatsController::class, 'getLiveStats'])->name('routers.live-stats');
    Route::resource('packages', PackageController::class);
    
    // =====================================================
    // Invoice Management
    // =====================================================
    Route::get('/invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])
        ->name('invoices.download');
    Route::get('/customers/{customer}/invoices', [InvoiceController::class, 'customerInvoices'])
        ->name('customers.invoices');
    
    // =====================================================
    // Payment Entry
    // =====================================================
    Route::get('/invoices/{invoice}/pay', [PaymentController::class, 'create'])
        ->name('invoices.pay');
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])
        ->name('payments.store');
    Route::post('/payments/bulk-import', [PaymentController::class, 'bulkImport'])
        ->name('payments.bulk-import');

    // =====================================================
    // Settings System
    // =====================================================
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
});

// Auth Routes (Login, Register, etc.)
require __DIR__.'/auth.php';
