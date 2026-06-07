import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** Map a CSS variable holding an "R G B" triplet into a Tailwind color
 *  that still supports opacity utilities (bg-primary/50). */
const v = (name) => `rgb(var(${name}) / <alpha-value>)`;

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                mono: ['"JetBrains Mono"', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                canvas: v('--color-canvas'),
                'canvas-soft': v('--color-canvas-soft'),
                'surface-card': v('--color-surface-card'),
                'surface-strong': v('--color-surface-strong'),
                ink: v('--color-ink'),
                body: v('--color-body'),
                muted: v('--color-muted'),
                hairline: v('--color-hairline'),
                'hairline-strong': v('--color-hairline-strong'),
                primary: v('--color-primary'),
                'primary-active': v('--color-primary-active'),
                'on-primary': v('--color-on-primary'),
                success: v('--color-success'),
                error: v('--color-error'),
                'error-strong': v('--color-error-strong'),
                'timeline-thinking': v('--color-timeline-thinking'),
                'timeline-grep': v('--color-timeline-grep'),
                'timeline-read': v('--color-timeline-read'),
                'timeline-edit': v('--color-timeline-edit'),
                'timeline-done': v('--color-timeline-done'),
            },
            borderRadius: {
                pill: '9999px',
            },
        },
    },

    plugins: [forms],
};
