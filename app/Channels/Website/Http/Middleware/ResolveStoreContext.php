<?php

namespace App\Channels\Website\Http\Middleware;

use App\Models\Reseller;
use App\Services\Core\Store\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * از روی prefix مسیر، StoreContext درست را bind می‌کند — یک بار، اینجا،
 * به‌جای این‌که هر Controller خودش `if (request()->route('slug'))`
 * بنویسد (تکرار پراکنده‌ی منطق تشخیص فروشگاه ممنوع است).
 *
 * سه کار این میان‌افزار:
 *   ۱) اگر route پارامتر {slug} دارد → Reseller متناظر را پیدا کن.
 *   ۲) اگر پیدا نشد یا نماینده غیرفعال بود → 404 (نه 403 — از دید یک
 *      بازدیدکننده‌ی خارجی، فروشگاهی که وجود ندارد باید دقیقاً مثل یک
 *      URL نامعتبر رفتار کند، نه این‌که وجودش را با کد 403 لو بدهد).
 *   ۳) StoreContext ساخته‌شده را در Container bind کن تا در تمام طول
 *      همین Request، هر Controller/Facade Service با
 *      `app(StoreContext::class)` یا Type-hint همان یک Instance را
 *      بگیرد — دقیقاً «تغییرناپذیر بعد از ساخت» که خودِ StoreContext
 *      روی آن تاکید دارد.
 */
class ResolveStoreContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->route('slug');

        if ($slug === null) {
            $context = StoreContext::main();
        } else {
            $reseller = Reseller::query()->where('slug', $slug)->first();

            // نماینده‌ای که وجود ندارد یا Core آن را «قابل عملیات» نمی‌داند
            // (بند ۱۵/۱۶ زیرسند) از دید Website اصلاً وجود ندارد.
            if (! $reseller) {
                abort(404);
            }

            $context = StoreContext::reseller($reseller);

            if (! $context->isOperational()) {
                abort(404);
            }
        }

        app()->instance(StoreContext::class, $context);

        // {slug} فقط برای تشخیص فروشگاه است. Laravel پارامترهای route را
        // «به‌ترتیب» به آرگومان‌های Controller می‌دهد (نه با نام)، پس اگر
        // slug در route بماند، جلوتر از {product}/{order}/... قرار می‌گیرد و
        // به‌جای آن‌ها در آرگومان `int $product` می‌نشیند → TypeError / 500.
        // بعد از ساخت StoreContext دیگر لازم نیست؛ هر کس slug می‌خواهد از
        // StoreContext می‌گیرد.
        if ($slug !== null) {
            $request->route()?->forgetParameter('slug');
        }

        // برای استفاده‌ی مستقیم و صریح در View‌ها (بند ۴۶: Branding نماینده)
        // بدون این‌که View مجبور شود خودش app(StoreContext::class) بزند.
        view()->share('storeContext', $context);

        return $next($request);
    }
}
