<?php

namespace App\Channels\Website\Http\Controllers\Account;

use App\Channels\Website\Services\WebsiteServiceFacade;
use App\Models\Account;
use App\Models\CustomerAccount;
use App\Services\Core\Store\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Accounts / Service Management (B3.2) — فهرست سرویس‌ها، جزئیات با مصرف زنده، و تمدید.
 *
 * مالکیت اینجا با همان `customer_account_id` سنجیده می‌شود که `EnsureCustomerAccountResolved`
 * (میان‌افزار store.customer) از قبل برای همین User در همین Context Resolve کرده — پس این کوئری
 * خودش‌به‌خود Context-isolated است (بند ۳۱)، بدون نیاز به فیلتر reseller_id جداگانه.
 *
 * هیچ منطق مالی/تصمیمی اینجا نیست: «وضعیت، مصرف، پیش‌فاکتور و تمدید» همه از Core
 * (AccountManagementService/RenewalService) می‌آید و WebsiteServiceFacade فقط Adapter است.
 *
 * اطلاعات اتصال (Config/QR) عمداً محدود نگه داشته شده: فقط `subscription_url` نمایش داده می‌شود،
 * نه محتوای خامِ `config_data` (بند ۵۲). Refund/Retry کاملاً Admin-only است (مثل ربات).
 */
class AccountsController
{
    public function __construct(protected WebsiteServiceFacade $services) {}

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
            'url' => fn (string $name, array $params = []) => $this->services->url($name, $store, $params),
        ]);
    }

    public function show(Request $request, int $account, StoreContext $store): View
    {
        $accountModel = $this->ownedAccountOrFail($request, $account);

        return view('website.account.accounts.show', [
            'account' => $accountModel,
            'overview' => $this->services->overview($accountModel),
            'store' => $store,
            'url' => fn (string $name, array $params = []) => $this->services->url($name, $store, $params),
        ]);
    }

    /**
     * Renewal — دقیقاً همانند Core و ربات تلگرام؛ همه‌چیز در WebsiteServiceFacade/RenewalService.
     * فرم یک توکن یکتا می‌فرستد (S-07) تا دو کلیک/Refresh دو بار پول نگیرد.
     */
    public function renew(Request $request, int $account, StoreContext $store): RedirectResponse
    {
        $accountModel = $this->ownedAccountOrFail($request, $account);
        $back = $this->services->url('accounts.show', $store, [$accountModel->id]);

        $result = $this->services->renew($accountModel, (string) $request->input('idempotency_token', ''));

        if (! $result['ok']) {
            return redirect($back)->with('renewal_error', $result['message']);
        }

        return redirect($back)->with('renewal_success', [
            'balance_before' => $result['before'],
            'balance_after' => $result['after'],
        ]);
    }

    /** به‌روزرسانی دستیِ مصرف از پنل (Throttle در Core: ۱۲۰ ثانیه برای هر سرویس). */
    public function refreshUsage(Request $request, int $account, StoreContext $store): RedirectResponse
    {
        $accountModel = $this->ownedAccountOrFail($request, $account);

        [$tone, $message] = $this->services->refreshUsage($accountModel);

        return redirect($this->services->url('accounts.show', $store, [$accountModel->id]))
            ->with('usage_notice', ['tone' => $tone, 'message' => $message]);
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
