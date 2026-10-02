<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    protected $fillable = [
        'user_id', 'customer_account_id', 'payment_method_id', 'amount', 'purpose',
        'receipt_image', 'depositor_name', 'status', 'gateway_reference',
        'gateway_response', 'reviewed_by', 'reviewed_at',
        'wallet_owner_type', 'reseller_id', 'reviewed_by_reseller_id',
    ];

    protected $casts = [
        'amount' => 'integer',
        'gateway_response' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewed_by');
    }

    /** نماینده‌ای که این پرداخت به آن مربوط است — یا چون کیف‌پول خودِ نماینده شارژ می‌شود، یا چون مشتریِ همین نماینده کیف‌پول شخصی‌اش را شارژ می‌کند */
    public function reseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class);
    }

    /** اگر تاییدکننده خودِ نماینده بوده (نه ادمین اصلی) */
    public function reviewerReseller(): BelongsTo
    {
        return $this->belongsTo(Reseller::class, 'reviewed_by_reseller_id');
    }

    /**
     * واکشی امنِ یک پرداختِ در انتظار، برای جریان آپلود رسید/نام
     * واریزکننده در ربات (P1 گزارش امنیتی، مورد #12).
     *
     * پیش از این، هندلرها مستقیماً `Payment::whereKey($paymentId)`
     * می‌زدند؛ یعنی هر کسی که می‌توانست payload وضعیت مکالمه را
     * دست‌کاری کند (یا صرفاً یک id معتبرِ متعلق به شخص دیگر حدس بزند)
     * می‌توانست رسید و نامِ واریزکننده‌ی پرداختِ دیگری را بازنویسی کند.
     * `payment_id` به‌تنهایی هیچ‌وقت یک مرز امنیتی نیست — این متد همه‌ی
     * ابعاد مالکیت را با هم اعمال می‌کند و در صورت عدم تطابق null
     * برمی‌گرداند تا صداکننده بی‌صدا متوقف شود.
     *
     * @param  'user'|'reseller'  $walletOwnerType
     */
    public static function findPendingForReceipt(
        int|string $paymentId,
        User $user,
        ?Reseller $reseller = null,
        string $walletOwnerType = 'user',
    ): ?self {
        return static::query()
            ->whereKey($paymentId)
            ->where('status', 'pending')
            ->where('wallet_owner_type', $walletOwnerType)
            ->when(
                $walletOwnerType === 'reseller',
                // شارژ اعتبار خودِ نماینده: مالک، همان نماینده است
                fn ($q) => $q->where('reseller_id', $reseller?->id),
                // شارژ کیف‌پول شخصی: مالک، همان کاربر است. reseller_id
                // هم باید بخواند (برای ربات اصلی null است) تا رسیدِ
                // مشتریِ یک نماینده از مسیر ربات دیگری قابل‌دستکاری نباشد.
                fn ($q) => $q->where('user_id', $user->id)->where('reseller_id', $reseller?->id),
            )
            ->first();
    }
}
