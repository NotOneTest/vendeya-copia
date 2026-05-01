<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Vendeya\AuthController;
use App\Http\Controllers\Vendeya\PosController;

Route::get('/', function () {
    return redirect()->route('vendeya.login');
});

Route::prefix('vendeya')->name('vendeya.')->group(function () {
    Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
    Route::get('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/pos', [PosController::class, 'index'])->name('pos');
    Route::get('/api/products', [PosController::class, 'getProducts'])->name('api.products');
    Route::get('/api/customers', [PosController::class, 'getCustomers'])->name('api.customers');
    Route::post('/customer/create', [PosController::class, 'createCustomer'])->name('customer.create');
    Route::post('/api/customers/create', [PosController::class, 'createCustomer'])->name('api.customers.create');
    Route::post('/api/sales', [PosController::class, 'createSale'])->name('api.sales.create');
    Route::post('/sale/create', [PosController::class, 'createSale'])->name('sale.create');
    
    Route::get('/api/test/series', [PosController::class, 'testSeries'])->name('api.test.series');
    
    Route::get('/cash/status', [PosController::class, 'checkCashStatus'])->name('cash.status');
    Route::post('/cash/open', [PosController::class, 'openCash'])->name('cash.open');
    Route::post('/cash/close', [PosController::class, 'closeCash'])->name('cash.close');
    Route::get('/cash/records', [PosController::class, 'getCashRecords'])->name('cash.records');
    
    Route::get('/api/document/{externalId}', [PosController::class, 'getDocumentByExternalId'])->name('api.document');
    
    // Voucher routes
    Route::get('/api/vouchers/balance/{doc}', [PosController::class, 'getVoucherBalance'])->name('api.vouchers.balance');
    Route::post('/api/vouchers/discount', [PosController::class, 'discountVoucher'])->name('api.vouchers.discount');
    Route::post('/api/vouchers/create', [PosController::class, 'createSale'])->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])->name('api.vouchers.create');
});
