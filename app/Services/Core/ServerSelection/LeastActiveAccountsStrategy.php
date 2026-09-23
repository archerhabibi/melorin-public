<?php

namespace App\Services\Core\ServerSelection;

use App\Models\Category;
use App\Models\ServerPanel;

/**
 * معیار پیش‌فرض نسخه اولیه (بند ۶.۲): سروری که کمترین تعداد اکانت فعال
 * را دارد انتخاب می‌شود، تا بار بین سرورها متعادل توزیع شود.
 *
 * فاز A2 سند v2.1 (بند ۶۵): سرورهایی که به `capacity`شان رسیده‌اند از
 * ابتدا از فهرستِ گزینه‌ها کنار گذاشته می‌شوند (نه این‌که انتخاب شوند و
 * بعداً رزروشان شکست بخورد) — `capacity` خالی یعنی «بدون سقف».
 */
class LeastActiveAccountsStrategy implements ServerSelectionStrategy
{
    protected const MAX_CANDIDATES = 5;

    public function select(Category $category): ?ServerPanel
    {
        return $this->eligibleQuery($category)->first();
    }

    public function selectAndReserve(Category $category): ?ServerPanel
    {
        // چند گزینه‌ی برتر را از قبل می‌گیریم، نه این‌که بعد از هر
        // شکستِ رزرو دوباره از دیتابیس بپرسیم: بین رزروِ گزینه‌ی اول و
        // شکستش، وضعیتِ بقیه‌ی گزینه‌ها عملاً عوض نشده — فقط همانی که
        // Race را باخت دیگر واجد شرایط نیست، و همین لیست همان را نشان
        // می‌دهد.
        $candidates = $this->eligibleQuery($category)->limit(self::MAX_CANDIDATES)->get();

        foreach ($candidates as $panel) {
            if ($panel->reserveCapacitySlot()) {
                return $panel;
            }
        }

        return null;
    }

    protected function eligibleQuery(Category $category)
    {
        return $category->serverPanels()
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('capacity')->orWhereColumn('active_accounts_count', '<', 'capacity');
            })
            ->orderBy('active_accounts_count');
    }
}
