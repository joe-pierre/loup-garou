import defaultTheme from 'tailwindcss/defaultTheme';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.vue',
    ],
    theme: {
        extend: {
            colors: {
                night: {
                    deep:    '#0a0f1e',
                    card:    '#111827',
                    darkest: '#030712',
                },
                gold: {
                    DEFAULT: '#c9a84c',
                    light:   '#e2c16e',
                },
                parchment: '#e8e0d0',
                blood: {
                    DEFAULT: '#8b0000',
                    light:   '#ff4444',
                },
                seer: '#7c3aed',
            },
            fontFamily: {
                sans:     ['Crimson Text', ...defaultTheme.fontFamily.serif],
                medieval: ['Cinzel', 'serif'],
                body:     ['Crimson Text', 'serif'],
                // Alias rétrocompat (vues existantes utilisent font-cinzel en CSS inline)
                cinzel:   ['Cinzel', 'serif'],
            },
            boxShadow: {
                'gold':    '0 0 12px rgba(201,168,76,0.35)',
                'gold-lg': '0 0 30px rgba(201,168,76,0.25)',
                'blood':   '0 0 12px rgba(139,0,0,0.4)',
                'blood-lg':'0 0 30px rgba(139,0,0,0.25)',
                'seer':    '0 0 12px rgba(124,58,237,0.4)',
            },
            animation: {
                'float':      'float 3s ease-in-out infinite',
                'pulse-gold': 'pulse-gold 2s ease-in-out infinite',
            },
            keyframes: {
                float: {
                    '0%, 100%': { transform: 'translateY(0)' },
                    '50%':      { transform: 'translateY(-8px)' },
                },
                'pulse-gold': {
                    '0%, 100%': { boxShadow: '0 0 8px rgba(201,168,76,0.2)' },
                    '50%':      { boxShadow: '0 0 20px rgba(201,168,76,0.5)' },
                },
            },
        },
    },
    plugins: [],
};
