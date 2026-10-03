<?php

namespace App\Console\Commands;

use App\Services\Ops\CheckResult;
use App\Services\Ops\Preflight;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * php artisan melorin:preflight [--group=all|config|runtime|data] [--strict] [--json] [--log]
 *
 * Exit code: 0 همه OK (یا فقط warn)، 1 حداقل یک fail (یا با --strict یک warn).
 * فقط می‌خواند؛ هیچ داده‌ای را تغییر نمی‌دهد.
 */
class PreflightCommand extends Command
{
    protected $signature = 'melorin:preflight
        {--group=all : config | runtime | data | all}
        {--strict : warn را هم شکست حساب کن}
        {--json : خروجی JSON}
        {--log : شکست‌ها را در لاگ برنویس (برای Scheduler/Alert)}';

    protected $description = 'Pre-flight check of config, runtime and financial data integrity (Staging/Production)';

    public function handle(Preflight $preflight): int
    {
        $group = (string) $this->option('group');
        if (! in_array($group, ['all', 'config', 'runtime', 'data'], true)) {
            $this->error('--group باید یکی از config|runtime|data|all باشد.');

            return self::INVALID;
        }

        $results = $preflight->run($group);

        if ($this->option('log')) {
            foreach ($results as $c) {
                if ($c->status === CheckResult::FAIL) {
                    Log::error('melorin_preflight_fail', $c->toArray());
                } elseif ($c->status === CheckResult::WARN) {
                    Log::warning('melorin_preflight_warn', $c->toArray());
                }
            }
        }

        $fails = count(array_filter($results, fn (CheckResult $c) => $c->status === CheckResult::FAIL));
        $warns = count(array_filter($results, fn (CheckResult $c) => $c->status === CheckResult::WARN));

        if ($this->option('json')) {
            $this->line(json_encode([
                'env' => config('app.env'),
                'fail' => $fails,
                'warn' => $warns,
                'checks' => array_map(fn (CheckResult $c) => $c->toArray(), $results),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($results as $c) {
                $mark = ['ok' => '✓', 'warn' => '!', 'fail' => '✗'][$c->status];
                $line = sprintf('%s [%s] %s%s', $mark, $c->group, $c->name, $c->detail !== '' ? " — {$c->detail}" : '');
                match ($c->status) {
                    CheckResult::FAIL => $this->error($line),
                    CheckResult::WARN => $this->warn($line),
                    default => $this->line($line),
                };
            }
            $this->newLine();
            $this->line('env='.config('app.env')."  fail={$fails}  warn={$warns}");
        }

        return ($fails > 0 || ($this->option('strict') && $warns > 0)) ? self::FAILURE : self::SUCCESS;
    }
}
