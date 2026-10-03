<?php

namespace App\Channels\Website\Support;

use RuntimeException;

/** شکست در Exchange/اعتبارسنجی توکن گوگل. پیام فقط برای Log داخلی است، نه نمایش به کاربر. */
class GoogleOAuthException extends RuntimeException {}
