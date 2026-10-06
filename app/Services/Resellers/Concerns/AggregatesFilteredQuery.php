<?php

namespace App\Services\Resellers\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Query فیلترشده‌ی یک جدول Filament را برای Aggregate آماده می‌کند (B5.4/B5.5): ستون‌های انتخابی، ترتیب،
 * Limit/Offset و Eager-load حذف می‌شوند ولی همه‌ی شرط‌ها (Scope، جست‌وجو، فیلتر) می‌مانند ⇒ عددهای بالای صفحه
 * دقیقاً همان مجموعه‌ای را می‌شمارند که جدول نشان می‌دهد.
 */
trait AggregatesFilteredQuery
{
    private function aggregateBase(Builder $filtered): \Illuminate\Database\Query\Builder
    {
        $base = (clone $filtered)->withoutEagerLoads();
        $query = $base->getQuery();
        $query->columns = null;
        // ستون‌های محاسبه‌شده (selectSub) Binding خودشان را در `select` می‌گذارند؛ با حذف ستون‌ها باید آن‌ها هم برود،
        // وگرنه شمار Bindingها با ? های SQL نمی‌خواند («column index out of range»).
        $query->bindings['select'] = [];
        $query->orders = null;
        $query->bindings['order'] = [];
        $query->limit = null;
        $query->offset = null;

        return $base->toBase();
    }

    private function countWhen(string $condition, string $alias): string
    {
        return "count(case when {$condition} then 1 end) as {$alias}";
    }

    private function sumWhen(string $condition, string $expression, string $alias): string
    {
        return "coalesce(sum(case when {$condition} then {$expression} else 0 end), 0) as {$alias}";
    }
}
