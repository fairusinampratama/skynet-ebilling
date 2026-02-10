<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AnalyticsController;
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
    Route::resource('packages', PackageController::class);
    Route::resource('areas', \App\Http\Controllers\AreaController::class);
    
    // =====================================================
    // Invoice Management
    // =====================================================
    Route::get('/invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])
        ->name('invoices.create');
    Route::post('/invoices', [InvoiceController::class, 'store'])
        ->name('invoices.store');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])
        ->name('invoices.void');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
        ->name('invoices.destroy');
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
    // Analytics & Reports
    // =====================================================
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('analytics.index');
    Route::get('/api/analytics/revenue-trend', [AnalyticsController::class, 'revenueTrend'])->name('api.analytics.revenue-trend');
    Route::get('/api/analytics/mrr', [AnalyticsController::class, 'mrr'])->name('api.analytics.mrr');
    Route::get('/api/analytics/collection-rate', [AnalyticsController::class, 'collectionRate'])->name('api.analytics.collection-rate');
    Route::get('/api/analytics/revenue-by-area', [AnalyticsController::class, 'revenueByArea'])->name('api.analytics.revenue-by-area');
    Route::get('/api/analytics/package-performance', [AnalyticsController::class, 'packagePerformance'])->name('api.analytics.package-performance');
    Route::get('/api/analytics/payment-methods', [AnalyticsController::class, 'paymentMethods'])->name('api.analytics.payment-methods');
    Route::get('/api/analytics/outstanding-aging', [AnalyticsController::class, 'outstandingAging'])->name('api.analytics.outstanding-aging');
    Route::get('/api/analytics/customer-growth', [AnalyticsController::class, 'customerGrowth'])->name('api.analytics.customer-growth');

    // =====================================================
    // Settings System
    // =====================================================
    Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
    Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
});

// Auth Routes (Login, Register, etc.)
require __DIR__.'/auth.php';
