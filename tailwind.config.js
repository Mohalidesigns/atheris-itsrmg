import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['"Avenir Next LT Pro"', 'Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                atheris: {
                    navy: '#0A1F44',
                    'navy-50': '#E6E9F0',
                    'navy-100': '#C3CAD9',
                    'navy-200': '#8995B4',
                    'navy-700': '#1A2F54',
                    'navy-800': '#0F2346',
                    gold: '#C9A86A',
                    'gold-50': '#FAF5EA',
                    'gold-100': '#EDE0BE',
                    'gold-200': '#D9BE85',
                    'gold-600': '#A8894F',
                    green: '#2D7D46',
                    charcoal: '#2D3748',
                    critical: '#B3261E',
                    warning: '#E5A100',
                    success: '#2D7D46',
                    info: '#1D4ED8',
                },
            },
        },
    },

    plugins: [forms],
};
