import defaultTheme from 'tailwindcss/defaultTheme';

/** رنگ از توکن CSS با پشتیبانی alpha (مثلاً bg-primary/10) */
const token = (name) => `rgb(var(--c-${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: ['selector', '[data-theme="dark"]'],
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            fontFamily: {
                sans: ['"Vazirmatn Variable"', 'Vazirmatn', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                bg: token('bg'),
                surface: { DEFAULT: token('surface'), 2: token('surface-2') },
                text: token('text'),
                muted: token('muted'),
                subtle: token('subtle'),
                border: { DEFAULT: token('border'), strong: token('border-strong') },
                success: { DEFAULT: token('success'), soft: token('success-soft') },
                warning: { DEFAULT: token('warning'), soft: token('warning-soft') },
                danger: { DEFAULT: token('danger'), soft: token('danger-soft') },
                info: { DEFAULT: token('info'), soft: token('info-soft') },
                brand: 'var(--brand)',
                'on-brand': 'var(--brand-contrast, #fff)',
            },
            borderColor: { DEFAULT: token('border') },
            borderRadius: { sm: 'var(--radius-sm)', md: 'var(--radius-md)', lg: 'var(--radius-lg)' },
            boxShadow: { card: 'var(--shadow-card)', pop: 'var(--shadow-pop)' },
        },
    },
    plugins: [],
};
