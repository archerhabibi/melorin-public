<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Models\Account;
use App\Models\CustomerAccount;
use App\Services\Core\Renewal\RenewalFailedException;
use App\Services\Core\Renewal\RenewalService;
use App\Services\Core\Store\StoreContext;
use App\Services\Core\WalletService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use App\Exceptions\InsufficientBalanceException;

/**
 * فاز W4 بند ۳ Roadmap («Accounts — نمایش اکانت‌های VPN از Core»، بند
 * ۳۳ زیرسند: «بر اساس CustomerAccount و Context Core نمایش داده
 * می‌شوند»). مالکیت اینجا با همان `customer_account_id` سنجیده
 * می‌شود که `EnsureCustomerAccountResolved` (میان‌افزار store.customer،
 * فاز W1) از قبل برای همین User در همین Context Resolve کرده — پس
 * این کوئری خودش‌به‌خود Context-isolated است (بند ۳۱)، بدون نیاز به
 * فیلتر reseller_id جداگانه‌ای که Order نیاز دارد.
 *
 * اطلاعات اتصال (Config/QR) عمداً محدود نگه داشته شده: فقط
 * `subscription_url` (لینکی که خودِ کاربر باید در کلاینت VPN وارد
 * کند) نمایش داده می‌شود، نه محتوای خامِ `config_data` (که رمزنگاری‌
 * شده و برای نمایش مستقیم طراحی نشده — بند ۵۲: «هیچ داده‌ای از Client
 * نباید Trusted فرض شود» به همان اندازه یعنی داده‌ی حساس هم نباید
 * بدون دلیل به Client فرستاده شود).
 */
class AccountsController
{
    public function __construct(
        protected WalletService $walletService,
    ) {}

    public function index(Request $request, StoreContext $store): View
    {
        /** @var CustomerAccount|null $customer */
        $customer = $request->attributes->get('customerAccount');

        $accounts = Account::query()
            ->with('product')
            ->where('customer_account_id', $customer?->id)
            ->latest('id')
            ->paginate(15);

        return view('website.account.accounts.index', [
            'accounts' => $accounts,
            'store' => $store,
        ]);
    }

    public function show(Request $request, int $account, StoreContext $store): View|Response
    {
        $accountModel = $this->ownedAccountOrFail($request, $account);

        return view('website.account.accounts.show', [
            'account' => $accountModel,
            'store' => $store,
        ]);
    }

    /**
     * فاز W4 بند ۴ Roadmap («Renewal — دقیقاً همانند Core و ربات
     * تلگرام»، طبق تصمیم صریح). این متد عمداً هیچ منطق مالی/تصمیمی
     * ندارد — فقط همان سه‌تکه که `AccountsHandler::renew()` (ربات
     * تلگرام اصلی) دارد را روی یک درخواست HTTP تکرار می‌کند:
     *   ۱) یک پیش‌بررسیِ UX (نه دروازه‌ی واقعی — آن داخل
     *      RenewalService/PurchaseGuard است) که پیام روشن‌تری از
     *      InsufficientBalanceException خام بدهد؛
     *   ۲) صدا زدن مستقیم RenewalService::renew()؛
     *   ۳) نگاشت هر سه نوع خطا به همان متنی که ربات نشان می‌دهد
     *      (بند ۵۶ زیرسند: نگاشت خطا، نه پیام‌های مستقل تازه).
     * هیچ بازگشت‌وجه/تلاش‌مجدد دستی‌ای اینجا انجام نمی‌شود — دقیقاً
     * مثل ربات، چون آن دو Admin-only می‌مانند (تصمیم صریح).
     */
    public function renew(Request $request, int $account, StoreContext $store): RedirectResponse
    {
        $accountModel = $this->ownedAccountOrFail($request, $account);
        $product = $accountModel->product;

        $redirectRoute = $store->isReseller()
            ? route('website.store.accounts.show', [$store->reseller->slug, $accountModel->id])
            : route('website.accounts.show', $accountModel->id);

        $customer = $request->attributes->get('customerAccount');
        $balanceBeforeRenewal = $this->walletService->balance($customer);

        if ($balanceBeforeRenewal < $product->mainPrice()) {
            return redirect($redirectRoute)->with('renewal_error',
                'برای تمدید، ابتدا کیف پول خود را شارژ کنید. هزینه‌ی تمدید: '.number_format($product->mainPrice()).' تومان'
            );
        }

        try {
            app(RenewalService::class)->renew($accountModel);
        } catch (InsufficientBalanceException) {
            return redirect($redirectRoute)->with('renewal_error', 'موجودی کیف پول کافی نیست.');
        } catch (RenewalFailedException $e) {
            return redirect($redirectRoute)->with('renewal_error', "تمدید ناموفق بود: {$e->getMessage()}\n{$e->customerNotice()}");
        } catch (\RuntimeException $e) {
            // مثل ربات: هیچ بازگشت وجه دستی‌ای اینجا نمی‌دهیم — وضعیت
            // سفارش provision_failed می‌ماند تا ادمین رسیدگی کند.
            return redirect($redirectRoute)->with('renewal_error',
                "تمدید ناموفق بود: {$e->getMessage()}\nلطفاً با پشتیبانی تماس بگیرید؛ وضعیت سفارش شما ثبت شده است."
            );
        }

        $balanceAfterRenewal = $this->walletService->balance($customer);

        return redirect($redirectRoute)->with('renewal_success', [
            'balance_before' => $balanceBeforeRenewal,
            'balance_after' => $balanceAfterRenewal,
        ]);
    }

    protected function ownedAccountOrFail(Request $request, int $accountId): Account
    {
        /** @var CustomerAccount|null $customer */
        $customer = $request->attributes->get('customerAccount');

        $accountModel = Account::query()->with('product')->find($accountId);

        if (! $accountModel || ! $customer || $accountModel->customer_account_id !== $customer->id) {
            abort(404);
        }

        return $accountModel;
    }
}
