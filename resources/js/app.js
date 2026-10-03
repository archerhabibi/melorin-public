import './bootstrap';

// تصمیم ۹.۷: «Blade + Alpine.js + Tailwind». فقط برای تعامل‌های سبک UI
// (بند ۹.۷: «بدون Store سراسری، فقط Alpine local state») — بعد از
// `npm install` نصب می‌شود، پکیج از قبل در package.json اضافه شده.
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

// ---- Design System helpers (CSP-safe: بدون eval/inline) ----
// <button data-copy="#selector"> یا data-copy-value="متن"  → کپی در کلیپ‌بورد + بازخورد کوتاه.
document.addEventListener('click', async (e) => {
    const btn = e.target.closest('[data-copy]');
    if (!btn) return;

    const target = btn.dataset.copy ? document.querySelector(btn.dataset.copy) : null;
    const text = target ? (target.value ?? target.textContent) : btn.dataset.copyValue;
    if (!text) return;

    try {
        await navigator.clipboard.writeText(text.trim());
        const original = btn.textContent;
        btn.textContent = btn.dataset.copied || 'کپی شد';
        setTimeout(() => (btn.textContent = original), 1500);
    } catch (_) {
        target?.select?.(); // fallback: انتخاب متن تا کاربر دستی کپی کند
    }
});

// ---- Theme toggle (B1.4) ----
// <button data-theme-toggle> بین light/dark جابه‌جا می‌شود و انتخاب را ذخیره می‌کند (init: public/js/theme-init.js).
document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-theme-toggle]');
    if (!btn) return;

    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('melorin-theme', next); } catch (_) {}
});
