<?php

use App\Http\Controllers\Admin\TelegramReceiptController;
use Illuminate\Support\Facades\Route;

/*
 * توسط AdminPanelProvider::boot() لود می‌شود (loadRoutesFrom).
 */
Route::middleware(['web', 'auth:admin'])->group(function () {
    Route::get('/admin/payments/{payment}/receipt', TelegramReceiptController::class)
        ->name('admin.payments.receipt');
});
