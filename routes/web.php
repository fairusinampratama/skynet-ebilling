<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PackageController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\UserManagementController;
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

    Route::middleware('superadmin')->group(function () {
        Route::resource('users', UserManagementController::class)->except(['show']);
    });
    
    // =====================================================
    // Customer Management
    // =====================================================
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/export', [CustomerController::class, 'export'])->name('customers.export');
    Route::middleware('admin')->group(function () {
        Route::get('/customers/create', [CustomerController::class, 'create'])->name('customers.create');
        Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('/customers/{customer}/edit', [CustomerController::class, 'edit'])->name('customers.edit');
        Route::match(['put', 'patch'], '/customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
        Route::delete('/customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');
        Route::post('/customers/{customer}/isolate', [CustomerController::class, 'isolate'])->name('customers.isolate');
        Route::post('/customers/{customer}/reconnect', [CustomerController::class, 'reconnect'])->name('customers.reconnect');
    });
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->withTrashed()->name('customers.show');
    
    // =====================================================
    // Package Management
    // =====================================================
    Route::middleware('global-admin')->group(function () {
        Route::resource('packages', PackageController::class);
        Route::resource('areas', \App\Http\Controllers\AreaController::class);
        Route::post('/routers/{router}/test', [\App\Http\Controllers\RouterController::class, 'testConnection'])
            ->name('routers.test');
        Route::post('/routers/{router}/scan', [\App\Http\Controllers\RouterController::class, 'scanRouter'])
            ->name('routers.scan');
        Route::post('/routers/{router}/sync', [\App\Http\Controllers\RouterController::class, 'sync'])
            ->name('routers.sync');
        Route::post('/routers/sync-all', [\App\Http\Controllers\RouterController::class, 'syncAll'])
            ->name('routers.sync-all');
        Route::get('/api/routers/{router}/customers', [\App\Http\Controllers\RouterController::class, 'customers'])
            ->name('api.routers.customers');
        Route::get('/api/routers/{router}/profiles', [\App\Http\Controllers\RouterController::class, 'getProfiles'])
            ->name('api.routers.profiles');
        Route::get('/api/routers/{router}/live-stats', [\App\Http\Controllers\RouterController::class, 'liveStats'])
            ->name('api.routers.live-stats');
        Route::resource('routers', \App\Http\Controllers\RouterController::class);
        Route::post('/routers/{router}/sync-online', [\App\Http\Controllers\RouterController::class, 'syncOnlineStatus'])
            ->name('routers.sync-online');
    });
    
    // =====================================================
    // Invoice Management
    // =====================================================
    Route::get('/invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index');
    Route::get('/invoices/export', [InvoiceController::class, 'export'])
        ->name('invoices.export');
    Route::middleware('admin')->group(function () {
        Route::get('/invoices/create', [InvoiceController::class, 'create'])
            ->name('invoices.create');
        Route::post('/invoices', [InvoiceController::class, 'store'])
            ->name('invoices.store');
        Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])
            ->name('invoices.void');
        Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])
            ->name('invoices.destroy');
    });
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show');
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])
        ->name('invoices.download');
    Route::get('/customers/{customer}/invoices', [InvoiceController::class, 'customerInvoices'])
        ->name('customers.invoices');
    
    // =====================================================
    // Payment Entry
    // =====================================================
    Route::middleware('admin')->group(function () {
        Route::get('/invoices/{invoice}/pay', [PaymentController::class, 'create'])
            ->name('invoices.pay');
        Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])
            ->name('payments.store');
        Route::post('/payments/bulk-import', [PaymentController::class, 'bulkImport'])
            ->name('payments.bulk-import');
    });

    
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
    // Broadcast & Campaigns
    // =====================================================
    Route::get('/broadcasts', [\App\Http\Controllers\WaCampaignController::class, 'index'])->name('broadcasts.index');
    Route::middleware('admin')->group(function () {
        Route::get('/broadcasts/create', [\App\Http\Controllers\WaCampaignController::class, 'create'])->name('broadcasts.create');
        Route::post('/broadcasts', [\App\Http\Controllers\WaCampaignController::class, 'store'])->name('broadcasts.store');
        Route::post('/broadcasts/{campaign}/retry', [\App\Http\Controllers\WaCampaignController::class, 'retryFailed'])->name('broadcasts.retry');
    });
    Route::get('/broadcasts/{campaign}', [\App\Http\Controllers\WaCampaignController::class, 'show'])->name('broadcasts.show');

    // =====================================================
    // Settings System
    // =====================================================
    Route::middleware('global-admin')->group(function () {
        Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    });
});

// Auth Routes (Login, Register, etc.)
require __DIR__.'/auth.php';
