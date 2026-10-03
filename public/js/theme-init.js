/*
 * Theme init (B1.4) — باید همگام و در <head> اجرا شود تا قبل از اولین رندر data-theme ست شود (بدون فلاش).
 * فایل ثابتِ same-origin است، پس با CSP (script-src 'self') سازگار است؛ هیچ inline script ای لازم نیست.
 * انتخاب کاربر: 'light' | 'dark' | (نبودن = پیروی از سیستم‌عامل).
 */
(function () {
    var saved = null;
    try { saved = localStorage.getItem('melorin-theme'); } catch (e) {}
    var dark = saved ? saved === 'dark' : window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
})();
