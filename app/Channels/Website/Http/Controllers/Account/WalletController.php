<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Channels\Website\Services\WebsiteWalletFacade;
use App\Models\WalletTransaction;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Wallet Center (B3.3): موجودی، خلاصه‌ی ۳۰ روز اخیر، شارژهای اخیر (با وضعیت «منتظر چه کاری»)،
 * و گردش حساب فیلتر‌پذیر (جهت/نوع/تاریخ).
 *
 * این کنترلر خودش هیچ مسیر شارژی نمی‌سازد — «Charge» همان `ChargeController` است
 * (wallet.charge.show/store) و رسید همان `ReceiptController`؛ اینجا فقط لینک می‌دهیم.
 * منطق در Core است (`WalletCenterService`) و این صفحه فقط‌خواندنی است: باز کردنش Wallet نمی‌سازد
 * (قرارداد: `CUSTOMER-WALLET-CONTRACT.md` W1).
 */
class WalletController
{
    public function __construct(protected WebsiteWalletFacade $wallet) {}

    public function show(Request $request, StoreContext $store): View
    {
        $user = $request->user();
        $filter = $this->wallet->filter($request->query());

        return view('website.account.wallet', [
            'overview' => $this->wallet->overview($user, $store),
            'charges' => $this->wallet->charges($user, $store),
            'transactions' => $this->wallet->transactions($user, $store, $filter),
            'filter' => $filter,
            'typeLabels' => WalletTransaction::typeLabels(),
            'receiptUrl' => fn ($charge) => $this->wallet->receiptUrl($charge, $store),
            'orderUrl' => fn (int $orderId) => $this->wallet->orderUrl($orderId, $store),
            'store' => $store,
        ]);
    }
}
