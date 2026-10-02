<?php

return [
    /*
     * سوپر ادمین اولیه‌ی پنل Filament (App\Models\Admin)، اختیاری.
     * اگر ADMIN_EMAIL و ADMIN_PASSWORD در .env تعریف شده باشند،
     * database/seeders/AdminSeeder.php هنگام اجرای «php artisan
     * db:seed» یک سوپرادمین با این مشخصات می‌سازد — فقط اگر از قبل
     * ادمینی با همین ایمیل وجود نداشته باشد (رمز موجود بازنویسی
     * نمی‌شود).
     *
     * مناسب برای محیط توسعه/لوکال (مثل Laragon روی ویندوز) که اجرای
     * install.sh تعاملی (که برای سرور Ubuntu نوشته شده) ممکن نیست.
     * روی سرور تولید، ساخت سوپرادمین همچنان عمدتاً از طریق پرامپت
     * تعاملی install.sh انجام می‌شود؛ این تنها یک مسیر جایگزین/مکمل
     * است برای زمانی که ADMIN_EMAIL/ADMIN_PASSWORD در .env مقداردهی
     * شده باشند (مثلاً استقرار خودکار).
     */
    'super_admin' => [
        'name' => env('ADMIN_NAME', 'Administrator'),
        'email' => env('ADMIN_EMAIL'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
     * واحد پولی سیستم (Money Representation).
     *
     * همه‌ی مبالغ در دیتابیس و Core به‌صورت «عدد صحیحِ واحد کوچک» (Minor Unit)
     * ذخیره می‌شوند. decimals تعیین می‌کند هر ۱ واحد اصلی چند واحد کوچک دارد:
     *
     *     تومان  (IRT)  decimals=0  →  ۱ تومان = ۱
     *     ریال   (IRR)  decimals=0  →  ۱ ریال  = ۱
     *     دلار   (USD)  decimals=2  →  ۱ دلار  = ۱۰۰ سنت
     *     یورو   (EUR)  decimals=2
     *     تتر    (USDT) decimals=2 (یا ۶ برای دقت بیشتر)
     *
     * ⚠️ این تنظیم فقط «قبل از اولین Migration/راه‌اندازی» باید انتخاب شود.
     *    تغییر decimals روی دیتابیسِ دارای داده، مقدار همه‌ی موجودی‌ها را تغییر می‌دهد
     *    (مثلاً ۱۰۰۰ تومان با decimals=2 می‌شود ۱۰.۰۰). این سیستم «چندارزی هم‌زمان»
     *    نیست؛ یک نصب = یک ارز.
     *
     *    قفل: Migration مبالغ «code:decimals» را در جدول system_meta ثبت می‌کند و از آن
     *    به بعد هر مقدار متفاوتِ code/decimals با CurrencyLockException رد می‌شود
     *    (App\Support\CurrencyLock). label و symbol_position ظاهری‌اند و آزادانه قابل تغییرند.
     *
     * label: متنی که بعد از عدد نمایش داده می‌شود (تومان، $، USD، …).
     * symbol_position: after | before
     * درگاه زرین‌پال فقط با کد IRT (تومان) یا IRR (ریال) کار می‌کند؛ با ارزهای دیگر رد می‌شود.
     */
    'currency' => [
        'code' => env('MELORIN_CURRENCY_CODE', 'IRT'),
        'label' => env('MELORIN_CURRENCY_LABEL', 'تومان'),
        'decimals' => (int) env('MELORIN_CURRENCY_DECIMALS', 0),
        'symbol_position' => env('MELORIN_CURRENCY_POSITION', 'after'),

        // Migration مبالغ: اگر داده‌ی موجود اعشارِ بیش از decimals ارز دارد، پیش‌فرض متوقف می‌شود.
        // با true شدن، Half-Up گرد می‌شود (تصمیم آگاهانه‌ی صاحب پروژه).
        'allow_migration_rounding' => (bool) env('MELORIN_MONEY_ALLOW_ROUNDING', false),

        // مقادیر زیر «واحد اصلی ارز»اند (مثلاً ۱۰۰۰۰ تومان یا 5.50 دلار)، نه Minor Unit.
        // برای ارز دلاری مثلاً: MELORIN_MIN_TOPUP=5  MELORIN_TOPUP_PRESETS=10,25,50
        'topup' => [
            'min' => env('MELORIN_MIN_TOPUP', '10000'),                       // حداقل شارژ مشتری
            'supply_min' => env('MELORIN_MIN_SUPPLY_TOPUP', '100000'),        // حداقل شارژ اعتبار نماینده
            'presets' => env('MELORIN_TOPUP_PRESETS', '200000,500000,1000000'),
            'supply_presets' => env('MELORIN_SUPPLY_TOPUP_PRESETS', '1000000,5000000'),
        ],
    ],
];
