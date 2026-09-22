# Melorin

**Melorin** یک پلتفرم متن‌باز برای فروش و مدیریت سرویس‌های VPN از طریق ربات تلگرام و پنل مدیریت وب است.

## امکانات

- 🤖 ربات Telegram
- 💰 کیف پول داخلی
- 📦 مدیریت محصولات و پلن‌ها
- 🖥️ مدیریت چند ServerPanel
- 🔐 مدیریت اکانت‌های VPN
- 👨‍💼 پنل مدیریت Filament
- 🏪 معماری چندفروشگاهی (فروشگاه اصلی + نمایندگان، با کیف‌پول جدا)
- 🧾 کمیسیون و پاداش معرفی
- 🔄 نصب و بروزرسانی خودکار با `install.sh`

## معماری

از نسخه‌ی ۳.۱.۰ هسته‌ی ملورین به معماری چندفروشگاهی منتقل شده است.
برای درک ساختار (CustomerAccount، مرز تراکنش، سقف بدهی نماینده،
Idempotency و قوانین مالی) پیش از هر تغییری در کد، این را بخوانید:

**[ARCHITECTURE.md](ARCHITECTURE.md)**

## نصب و بروز رسانی

روی Ubuntu 22.04+:

```bash
curl -o install.sh -L https://raw.githubusercontent.com/archerhabibi/melorin/main/install.sh
sudo bash install.sh
```

## بروزرسانی

برای بروزرسانی یک نصب موجود (روی سرور، از ریشه‌ی پروژه):

```bash
sudo bash update-git.sh [--dry-run] [مسیر-نصب] [tag-یا-branch]
```

اسکریپت پیش از هر تغییری از دیتابیس بک‌آپ می‌گیرد، کد را از GitHub می‌گیرد،
migrationهای جدید را اجرا می‌کند، کش را بازمی‌سازد و در صورت خراب‌بودن Health Check
کد و دیتابیس را خودکار Rollback می‌کند. بدون آرگومان، آخرین release نصب می‌شود.

## مجوز

این پروژه تحت مجوز **GNU Affero General Public License v3.0 (AGPL-3.0)** منتشر شده است.

Copyright © 2026 Melorin
