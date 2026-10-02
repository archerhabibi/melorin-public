<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * ارز یا decimals بعد از Migration مبالغ تغییر کرده است (فاز ۵).
 * تغییر این مقادیر روی دیتابیسِ دارای داده، همه‌ی موجودی‌ها را بی‌صدا عوض می‌کند؛
 * به همین دلیل سیستم به‌جای ادامه‌ی کار، متوقف می‌شود.
 */
class CurrencyLockException extends RuntimeException {}
