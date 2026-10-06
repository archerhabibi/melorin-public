<?php

namespace App\Http\Controllers\Ops;

use App\Services\Resellers\Domains\ResellerDomainService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * B6.1 — «آیا برای این دامنه گواهی TLS صادر شود؟» (Caddy on_demand_tls `ask`، یا هر پراکسی مشابه).
 * 200 = دامنه‌ی تأییدشده‌ی نمایندگیِ فعال؛ در غیر این صورت 404. فقط یک بولین برمی‌گرداند و
 * هیچ‌چیز از نماینده فاش نمی‌کند. اگر `MELORIN_DOMAIN_ASK_TOKEN` تنظیم باشد، `?token=` لازم است.
 */
class DomainAllowedController
{
    public function __invoke(Request $request, ResellerDomainService $domains): Response
    {
        $token = (string) config('melorin.domains.ask_token');

        if ($token !== '' && ! hash_equals($token, (string) $request->query('token'))) {
            abort(403);
        }

        abort_unless($domains->isServable((string) $request->query('domain')), 404);

        return response('ok', 200);
    }
}
