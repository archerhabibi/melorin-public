<?php

namespace App\Filament\Reseller;

use App\Models\Reseller;
use Filament\Facades\Filament;

/**
 * طبق «اصل طلایی امنیت و جداسازی داده» (سند نیازمندی، بند ۲)، هر
 * Resource/Page این پنل باید داده را نسبت به همین Reseller فیلتر کند —
 * هرگز نسبت به id ای که در URL/Request می‌آید. از وقتی پنل به
 * Filament Multi-Tenancy مجهز شد (ر.ک. ResellerPanelProvider)، همین
 * Tenant حل‌شده توسط خودِ Filament منبع حقیقت است — Filament پیش از
 * رسیدن به هر Resource، از روی slug در URL آن را resolve و با
 * User::canAccessTenant() اعتبارسنجی کرده، پس اینجا نیازی به یک Query
 * دستیِ دوباره نیست.
 */
trait ResolvesCurrentReseller
{
    public static function currentReseller(): Reseller
    {
        $tenant = Filament::getTenant();

        abort_if(! $tenant instanceof Reseller, 403);

        return $tenant;
    }
}
