<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Channels\Website\Services\WebsiteWalletFacade;
use App\Services\Core\Store\StoreContext;
use Illuminate\View\View;

/**
 * Wallet: نمایش موجودی + شارژ با همان مسیرهای Payment (Zarinpal /
 * Card-to-Card).
 *
 * این کنترلر خودش هیچ مسیر شارژی نمی‌سازد — «Charge» همان
 * `ChargeController` است (route نام‌های wallet.charge.show/store). اینجا فقط صفحه‌ی
 * داشبورد کیف‌پول (موجودی + گردش حساب) است که با یک دکمه به همان
 * مسیر موجود لینک می‌دهد — نه بازسازی آن.
 */
class WalletController
{
    public function __construct(protected WebsiteWalletFacade $wallet) {}

    public function show(StoreContext $store): View
    {
        $user = auth()->user();

        return view('website.account.wallet', [
            'balance' => $this->wallet->balance($user, $store),
            'transactions' => $this->wallet->transactions($user, $store),
            'store' => $store,
        ]);
    }
}
