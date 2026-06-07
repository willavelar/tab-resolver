import { ref } from 'vue';

const STORAGE_KEY = 'theme';

function systemPrefersDark() {
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function read() {
    try {
        return localStorage.getItem(STORAGE_KEY); // 'light' | 'dark' | null
    } catch (e) {
        return null;
    }
}

function apply(isDark) {
    document.documentElement.classList.toggle('dark', isDark);
}

// Shared singleton state so every toggle instance stays in sync.
const isDark = ref(read() ? read() === 'dark' : systemPrefersDark());

// Keep `system` mode reactive to OS changes (only while user hasn't overridden).
window
    .matchMedia('(prefers-color-scheme: dark)')
    .addEventListener('change', (e) => {
        if (!read()) {
            isDark.value = e.matches;
            apply(isDark.value);
        }
    });

export function useTheme() {
    function toggle() {
        isDark.value = !isDark.value;
        apply(isDark.value);
        try {
            localStorage.setItem(STORAGE_KEY, isDark.value ? 'dark' : 'light');
        } catch (e) {}
    }

    return { isDark, toggle };
}
