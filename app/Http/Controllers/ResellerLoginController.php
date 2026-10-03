<?php

namespace App\Http\Controllers;

use App\Models\Reseller;
use App\Services\Resellers\ResellerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * پنل نماینده هیچ فرم ثبت‌نام/رمز عبوری ندارد — طبق بند ۴ سند
 * نیازمندی Reseller Platform («admin فقط لینک پنل وب مجاز را می‌دهد»).
 * ورود از طریق یک لینک امضاشده‌ی محدودزمان (Laravel Signed URL) انجام
 * می‌شود که ربات نماینده هنگام دستور «ادمین»/«/admin» می‌سازد
 * (ر.ک. ResellerBot\UpdateRouter::handleAdminCommand). چون لینک همیشه
 * از سمت سرور و فقط برای owner واقعیِ همان Reseller ساخته می‌شود، نیازی
 * به رمز عبور جداگانه نیست؛ امضا (signature) همان نقشِ احراز هویت را
 * بازی می‌کند و بعد از expire شدن، لینکِ قدیمی دیگر معتبر نیست.
 */
class ResellerLoginController
{
    public function __invoke(Request $request, Reseller $reseller, ResellerService $resellerService): RedirectResponse
    {
        $owner = $reseller->user;

        // این چک دومین لایه‌ی دفاعی است، مستقل از خودِ امضا؛ حتی اگر
        // یک لینکِ امضاشده‌ی قدیمی/دستکاری‌شده رد شود، این‌جا هم دوباره
        // enforce می‌شود که واقعاً owner همین Reseller است، نه کاربر
        // دیگری با id که به‌طور تصادفی/عمدی در URL جا گذاشته شده.
        if (! $owner || ! $resellerService->isOwner($reseller, $owner)) {
            abort(403);
        }

        Auth::guard('reseller')->login($owner);

        // S-06 (فاز ۸): Session Fixation — شناسه‌ی نشست باید پس از ورود عوض شود.
        $request->session()->regenerate();

        return redirect('/'.$reseller->slug);
    }
}
