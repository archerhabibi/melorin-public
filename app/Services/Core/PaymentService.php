<?php

namespace App\Services\Core;

use App\DataTransferObjects\GatewayInitiationResult;
use App\Events\PaymentConfirmed;
use App\Exceptions\ResellerScopeViolationException;
use App\Models\Admin;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Reseller;
use App\Models\User;
use App\Services\Core\Payments\InvalidPaymentTransitionException;
use App\Services\Core\Payments\PaymentGatewayFactory;
use App\Services\Core\Payments\PaymentStateMachine;
use App\Services\Core\Store\IdentityService;
use App\Services\Core\Store\StoreContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * PaymentService — تنها نقطه‌ی مجاز در سیستم برای ایجاد، تایید، رد و
 * بازگشتِ پرداخت (بند ۱۲ سند نیازمندی). مطابق اصل معماری بند ۳۴، هیچ
 * کانالی (ربات، سایت، نماینده) نباید مستقیم با یک درگاه پرداخت صحبت کند
 * یا وضعیت payments/wallets را دستی تغییر دهد.
 *
 * فاز A۱ سند v2.1 (بند ۵۳): هر تغییر وضعیت پرداخت — بدون استثنا — از
 * `PaymentStateMachine::transition()` عبور می‌کند؛ این کلاس دیگر
 * هیچ‌جا مستقیماً `$payment->update(['status' => ...])` نمی‌زند. چهار
 * وضعیت واقعی سیستم دقیقاً با enum ستون `payments.status` یکی است:
 *
 *     pending   ──→ confirmed
 *     pending   ──→ rejected
 *     confirmed ──→ refunded
 *
 * وضعیت‌های نظری «created»، «processing» و «partially_refunded» عمداً
 * پیاده نشده‌اند: پرداخت همیشه مستقیماً pending ساخته می‌شود (نه created)،
 * verify درگاه همگام (synchronous) انجام می‌شود (نه processing)، و
 * بازگشت‌وجهِ جزئی هنوز پشتیبانی نمی‌شود (بند ۵۳: «در صورت نیاز»).
 *
 * دو لایه‌ی متفاوت وجود دارد: بررسی‌های اولیه‌ی متدهای public (مثل
 * assertPending() پایین) صرفاً fail-fast هستند و روی داده‌ی lock‌نشده
 * کار می‌کنند — مرجع نیستند و می‌توانند دچار Race شوند. مرجعِ واقعی و
 * تنها منبع صحت، بررسی‌و-نوشتنِ اتمیکِ داخل تراکنش‌های پایین (finalize،
 * reject، rejectByReseller، refund) است: هرکدام ابتدا ردیف را با
 * lockForUpdate می‌گیرند و بعد از طریق همان StateMachine تغییر می‌دهند؛
 * پس اگر دو عملیات هم‌زمان به یک پرداخت برسند (مثلاً یک ادمین آن را رد
 * می‌کند درست وقتی webhook دارد تاییدش می‌کند)، دومی همیشه با
 * `InvalidPaymentTransitionException` متوقف می‌شود، نه این‌که بی‌صدا
 * وضعیت را از زیر عملیات اول عوض کند.
 */
class PaymentService
{
    public function __construct(
        protected WalletService $walletService,
        protected IdentityService $identity,
        protected PaymentStateMachine $stateMachine,
    ) {}

    /**
     * ایجاد یک پرداخت جدید و شروع آن نزد درگاه.
     *
     * برای شارژهای ربات نماینده دو حالت وجود دارد (طبق تصمیم صریح:
     * «شارژ حساب مشتری» را خودِ نماینده تایید می‌کند چون پول را مستقیم
     * می‌گیرد؛ «شارژ حساب نماینده» هم‌چنان با ادمین اصلی است):
     * - مشتری کیف‌پول شخصی‌اش را شارژ می‌کند: $reseller = نماینده‌ی
     *   همان ربات (برای Scope و تایید توسط او)، walletOwnerType='user'
     * - نماینده کیف‌پول خودش را شارژ می‌کند: $reseller = خودش،
     *   walletOwnerType='reseller' (تایید هم‌چنان با ادمین اصلی)
     *
     * @return array{payment: Payment, initiation: GatewayInitiationResult}
     */
    public function initiate(
        User $user,
        PaymentMethod $method,
        float $amount,
        string $purpose,
        ?Model $reference = null,
        ?string $receiptImage = null,
        ?Reseller $reseller = null,
        string $walletOwnerType = 'user',
    ): array {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ باید بزرگ‌تر از صفر باشد.');
        }

        // فاز ۱۲: خرید هیچ‌وقت Payment نمی‌سازد؛ خرید مستقیماً از Wallet هر
        // Context کسر می‌شود (بند ۱۶). تنها Payment واقعی، شارژ کیف‌پول است.
        // purpose=order کد مرده بود و دیگر پذیرفته نمی‌شود (مقدار enum در
        // دیتابیس برای ردیف‌های تاریخی دست‌نخورده می‌ماند).
        if ($purpose !== 'wallet_charge') {
            throw new \InvalidArgumentException("purpose نامعتبر: {$purpose}");
        }

        if (! in_array($walletOwnerType, ['user', 'reseller'], true)) {
            throw new \InvalidArgumentException("wallet_owner_type نامعتبر: {$walletOwnerType}");
        }

        if ($walletOwnerType === 'reseller' && ! $reseller) {
            throw new \InvalidArgumentException('برای شارژ کیف‌پول نماینده، reseller الزامی است.');
        }

        $payment = Payment::create([
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'amount' => $amount,
            'purpose' => $purpose,
            'receipt_image' => $receiptImage,
            'status' => 'pending',
            'wallet_owner_type' => $walletOwnerType,
            'reseller_id' => $reseller?->id,
        ]);

        $gateway = PaymentGatewayFactory::make($method);
        $result = $gateway->initiate($payment);

        $payment->update([
            'gateway_reference' => $result->gatewayReference,
            'gateway_response' => $result->rawResponse,
        ]);

        return ['payment' => $payment, 'initiation' => $result];
    }

    /**
     * تایید دستی توسط ادمین برای درگاه‌های دستی مثل کارت‌به‌کارت
     * (بند ۱۲: «بررسی رسید»، «تأیید پرداخت»). برای شارژ کیف‌پول شخصیِ
     * مشتریِ ربات نماینده استفاده نشود — آن مسیر confirmManualByReseller است.
     */
    public function confirmManual(Payment $payment, Admin $admin): Payment
    {
        if ($payment->wallet_owner_type === 'user' && $payment->reseller_id !== null) {
            throw new ResellerScopeViolationException('شارژ کیف‌پول مشتریِ ربات نماینده فقط توسط خودِ نماینده تایید می‌شود.');
        }

        $gateway = PaymentGatewayFactory::make($payment->paymentMethod);

        if (! $gateway->isManual()) {
            throw new \LogicException('این پرداخت از یک درگاه آنلاین است و باید از طریق callback تایید شود، نه دستی.');
        }

        $this->assertPending($payment);

        return $this->finalize($payment, admin: $admin);
    }

    /**
     * تایید «شارژ حساب مشتری» در ربات نماینده، توسط خودِ نماینده — طبق
     * تصمیم صریح: چون نماینده پول را مستقیم (نقدی/کارت‌به‌کارت با
     * مشتری‌اش) دریافت می‌کند، فقط او صلاحیت تشخیص واقعی‌بودن رسید را
     * دارد، نه ادمین اصلیِ پلتفرم.
     *
     * @throws ResellerScopeViolationException اگر این پرداخت اصلاً مال این نماینده نباشد یا در واقع شارژِ کیف‌پول خودِ نماینده باشد
     */
    public function confirmManualByReseller(Payment $payment, Reseller $reseller): Payment
    {
        if ($payment->reseller_id !== $reseller->id) {
            throw new ResellerScopeViolationException('این پرداخت متعلق به این نماینده نیست.');
        }

        if ($payment->wallet_owner_type !== 'user') {
            throw new ResellerScopeViolationException('شارژ کیف‌پول خودِ نماینده فقط توسط ادمین اصلی تایید می‌شود.');
        }

        $gateway = PaymentGatewayFactory::make($payment->paymentMethod);

        if (! $gateway->isManual()) {
            throw new \LogicException('این پرداخت از یک درگاه آنلاین است و باید از طریق callback تایید شود، نه دستی.');
        }

        $this->assertPending($payment);

        return $this->finalize($payment, reseller: $reseller);
    }

    /**
     * تایید از طریق callback یک درگاه آنلاین (مثل Zarinpal).
     * اگر تایید درگاه ناموفق باشد، پرداخت خودکار rejected می‌شود.
     */
    public function handleCallback(Payment $payment, array $callbackData): Payment
    {
        $gateway = PaymentGatewayFactory::make($payment->paymentMethod);

        if ($gateway->isManual()) {
            throw new \LogicException('این پرداخت دستی است و callback ندارد؛ از confirmManual استفاده کنید.');
        }

        // callback تکراری روی پرداخت already-confirmed نباید دوباره کیف پول را شارژ کند
        if ($payment->status === 'confirmed') {
            return $payment;
        }

        $this->assertPending($payment);

        $verification = $gateway->verify($payment, $callbackData);

        if (! $verification->success) {
            return $this->reject($payment, null, $verification->errorMessage);
        }

        $payment->update(['gateway_response' => $verification->rawResponse]);

        return $this->finalize($payment);
    }

    public function reject(Payment $payment, ?Admin $admin = null, ?string $reason = null): Payment
    {
        $this->assertPending($payment);

        return DB::transaction(function () use ($payment, $admin, $reason) {
            // قفل ردیف: بدون این، رد کردن می‌توانست دقیقاً روی همان لحظه‌ای
            // که یک تایید (confirmManual/handleCallback) در حال commit شدن
            // است اجرا شود و بعد از آن، status را بی‌صدا به rejected برگرداند
            // — در حالی که کیف‌پول از قبل شارژ شده. transition() زیر با همین
            // قفل، این حالت را با InvalidPaymentTransitionException می‌بندد.
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $payment = $this->stateMachine->transition($payment, 'rejected', [
                'reviewed_by' => $admin?->id,
                'reviewed_at' => now(),
            ]);

            app(AuditService::class)->record(
                'payment.rejected',
                $payment,
                before: ['status' => 'pending'],
                after: ['status' => 'rejected', 'reason' => $reason, 'amount' => (float) $payment->amount],
                actor: $admin,
            );

            return $payment;
        });
    }

    /**
     * @throws ResellerScopeViolationException اگر این پرداخت مال این نماینده نباشد یا شارژِ کیف‌پول خودِ نماینده باشد
     */
    public function rejectByReseller(Payment $payment, Reseller $reseller, ?string $reason = null): Payment
    {
        if ($payment->reseller_id !== $reseller->id || $payment->wallet_owner_type !== 'user') {
            throw new ResellerScopeViolationException('این پرداخت قابل‌ردکردن توسط این نماینده نیست.');
        }

        $this->assertPending($payment);

        return DB::transaction(function () use ($payment, $reseller, $reason) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $payment = $this->stateMachine->transition($payment, 'rejected', [
                'reviewed_by_reseller_id' => $reseller->id,
                'reviewed_at' => now(),
            ]);

            app(AuditService::class)->record(
                'payment.rejected',
                $payment,
                before: ['status' => 'pending'],
                after: ['status' => 'rejected', 'reason' => $reason, 'amount' => (float) $payment->amount],
                actor: $reseller,
            );

            return $payment;
        });
    }

    /**
     * بازگشت وجه یک پرداخت تاییدشده (بند ۱۲). فقط برای purpose=wallet_charge
     * معنا دارد چون مستقیماً از کیف پولِ هدف (کاربر یا نماینده، بسته به
     * wallet_owner_type) کسر می‌کند؛ اگر آن موجودی قبلاً خرج شده باشد،
     * InsufficientBalanceException پرتاب می‌شود — یعنی بازگشت وجه باید
     * با تنظیم دستی موجودی توسط ادمین دنبال شود.
     */
    public function refund(Payment $payment, ?Admin $admin = null): Payment
    {
        return DB::transaction(function () use ($payment, $admin) {
            $payment = Payment::query()
                ->lockForUpdate()
                ->findOrFail($payment->id);

            // پیش از کسر از کیف‌پول بررسی می‌شود، نه فقط قبل از نوشتنِ
            // status، تا اگر گذار نامعتبر بود هیچ کسری هم انجام نشود.
            $this->stateMachine->assertCanTransition($payment, 'refunded');

            if ($payment->purpose === 'wallet_charge') {
                $this->walletService->adminAdjust(
                    $this->resolveWalletOwner($payment),
                    -1 * (float) $payment->amount,
                    $payment,
                    "بازگشت وجه پرداخت #{$payment->id}"
                );
            }

            $payment = $this->stateMachine->transition($payment, 'refunded', [
                'reviewed_by' => $admin?->id ?? $payment->reviewed_by,
                'reviewed_at' => now(),
            ]);

            app(AuditService::class)->record(
                'payment.refunded',
                $payment,
                before: ['status' => 'confirmed'],
                after: ['status' => 'refunded', 'amount' => (float) $payment->amount],
                actor: $admin,
            );

            return $payment;
        });
    }

    protected function finalize(Payment $payment, ?Admin $admin = null, ?Reseller $reseller = null): Payment
    {
        return DB::transaction(function () use ($payment, $admin, $reseller) {
            // قفل ردیف + بازبینیِ وضعیت داخل همان تراکنش (P0 گزارش
            // امنیتی). بدون این، assertPending() که بیرون از تراکنش
            // اجرا می‌شود یک check-then-act کلاسیک است: دو درخواست
            // هم‌زمان (مثلاً دو کلیک ادمین یا دو تبِ باز) هر دو
            // pending می‌بینند، هر دو تأیید می‌کنند، و کیف پول دو بار
            // شارژ می‌شود. lockForUpdate درخواست دوم را تا پایان
            // تراکنش اول نگه می‌دارد و بعد آن را با وضعیت به‌روز
            // (confirmed) می‌بیند و رد می‌کند.
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            $payment = $this->stateMachine->transition($payment, 'confirmed', [
                'reviewed_by' => $admin?->id,
                'reviewed_by_reseller_id' => $reseller?->id,
                'reviewed_at' => now(),
            ]);

            // مقصدِ شارژ بسته به wallet_owner_type فرق می‌کند: کیف‌پول
            // شخصیِ کاربر (مشتری عادی یا مشتریِ یک نماینده) یا کیف‌پول
            // (اعتبار) خودِ نماینده نزد پلتفرم.
            if ($payment->purpose === 'wallet_charge') {
                $this->walletService->charge(
                    $this->resolveWalletOwner($payment),
                    (float) $payment->amount,
                    $payment,
                    "شارژ کیف پول — پرداخت #{$payment->id}"
                );
            }

            PaymentConfirmed::dispatch($payment->fresh());

            app(AuditService::class)->record(
                'payment.approved',
                $payment,
                before: ['status' => 'pending'],
                after: [
                    'status' => 'confirmed',
                    'amount' => (float) $payment->amount,
                    'purpose' => $payment->purpose,
                    'wallet_owner_type' => $payment->wallet_owner_type,
                ],
                actor: $admin ?? $reseller,
            );

            return $payment->fresh();
        });
    }

    protected function assertPending(Payment $payment): void
    {
        if ($payment->status !== 'pending') {
            throw new InvalidPaymentTransitionException(
                "گذار نامعتبر وضعیت پرداخت #{$payment->id}: وضعیت فعلی «{$payment->status}» است و پرداخت باید pending باشد."
            );
        }
    }

    /**
     * مقصد شارژ یک پرداخت (Wallet = User + StoreContext):
     *   - wallet_owner_type='reseller' → نماینده؛ WalletService آن را به Wallet صاحبِ
     *     نماینده در Main می‌رساند.
     *   - wallet_owner_type='user'     → CustomerAccount همان فروشگاهی که پرداخت برایش
     *     ثبت شده (reseller_id پرداخت؛ خالی = Main)، تا شارژ و خرج همیشه از یک
     *     Wallet واحد باشند.
     */
    protected function resolveWalletOwner(Payment $payment): Model
    {
        if ($payment->wallet_owner_type === 'reseller') {
            return $payment->reseller;
        }

        return $this->identity->resolveCustomerAccount(
            $payment->user,
            StoreContext::fromReseller($payment->reseller),
        );
    }
}
