import './bootstrap';

// تصمیم ۹.۷: «Blade + Alpine.js + Tailwind». فقط برای تعامل‌های سبک UI
// (بند ۹.۷: «بدون Store سراسری، فقط Alpine local state») — بعد از
// `npm install` نصب می‌شود، پکیج از قبل در package.json اضافه شده.
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();
