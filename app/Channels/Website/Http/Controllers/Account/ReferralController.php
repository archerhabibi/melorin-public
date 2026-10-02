<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Models\AffiliateSetting;
use App\Models\Commission;
use App\Models\CustomerAccount;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * فاز W4 بند ۵ Roadmap («Referral/Commission — دقیقاً همانند Core و
 * ربات تلگرام»، طبق تصمیم صریح). دقیقاً هم‌مضمون با
 * `MiscHandler::referral()` (ربات تلگرام اصلی):
 *   - لینک دعوت + تعداد زیرمجموعه‌ها (سطح User، نه Context — طبق
 *     همان منطق ربات، چون معرفی یک رابطه‌ی سراسری بین دو User است،
 *     نه مخصوصِ یک فروشگاه).
 *   - فقط همان پاداشی که واقعاً پرداخت می‌شود تبلیغ می‌شود (کامنت
 *     صریح خودِ ربات: «قبلاً وعده‌ی کمیسیون هم می‌داد در حالی که
 *     پرداخت نمی‌شد» — همین احتیاط اینجا هم رعایت شده).
 *
 * برخلاف تعداد زیرمجموعه‌ها، فهرست کمیسیون‌های پرداخت‌شده Context-
 * scoped است (بند ۳۱ زیرسند وب + CommissionAndBridgeTest: «کمیسیون به
 * کیف‌پول همان Contextی که خرید در آن رخ داده پرداخت می‌شود»)، پس با
 * `referrer_customer_account_id` فیلتر می‌شود، نه `referrer_id` خام.
 *
 * نکته‌ی مهم که عمداً در این پچ نیست: ثبتِ referrer_id هنگام
 * ثبت‌نام سایت (پارامتر ?ref=) — RegisteredUserController خارج از
 * مالکیت این پچ (Controllers/Account/) است و باید با صاحبِ همان
 * کنترلر هماهنگ شود؛ جزئیات در PHASE-W4-PART2 doc.
 */
class ReferralController
{
    public function show(Request $request, StoreContext $store): View
    {
        $user = $request->user();

        /** @var CustomerAccount|null $customer */
        $customer = $request->attributes->get('customerAccount');

        $settings = AffiliateSetting::current();

        $commissions = Commission::query()
            ->with('referredUser')
            ->where('referrer_customer_account_id', $customer?->id)
            ->latest('id')
            ->paginate(15);

        return view('website.account.referral', [
            'referralCode' => $user->id,
            'referredCount' => $user->referredUsers()->count(),
            'bonusAmount' => (int) $settings->referrer_bonus_amount,
            'commissions' => $commissions,
            'store' => $store,
        ]);
    }
}
