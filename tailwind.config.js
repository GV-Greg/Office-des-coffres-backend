import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';
const colors = require('tailwindcss/colors');

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    safelist:[
        { pattern: /text-(gray|yellow|salmon|teal|blueGray|blue|red|orange|lime|green|cyan|indigo|pink)-(100|200|300|400|500|600|700|800|900)/, },
        { pattern: /ring-offset-(gray|yellow|salmon|teal|blueGray|blue|red|orange|lime|green|cyan|indigo|pink)-(100|200|300|400|500|600|700|800|900)/, },
        { pattern: /ring-(gray|yellow|salmon|teal|blueGray|blue|red|orange|lime|green|cyan|indigo|pink)-(100|200|300|400|500|600|700|800|900)/, },
        { pattern: /bg-(gray|yellow|salmon|teal|blueGray|blue|red|orange|lime|green|cyan|indigo|pink)-(100|200|300|400|500|600|700|800|900)/, },
    ],

    theme: {
        colors: {
            transparent: 'transparent',
            current: 'currentColor',
            black: colors.black,
            white: colors.white,
            blueGray: colors.slate,
            gray: colors.gray,
            red: colors.red,
            orange: colors.orange,
            yellow: colors.yellow,
            lime: colors.lime,
            green: colors.green,
            teal: colors.teal,
            cyan: colors.cyan,
            blue: colors.sky,
            indigo: colors.violet,
            pink: colors.fuchsia,
            salmon: colors.rose,
        },
        fontSize: {
            'xs': '.75rem',
            'sm': '.875rem',
            'base': '1rem',
            'lg': '1.125rem',
            'xl': '1.25rem',
            '2xl': '1.5rem',
            '3xl': '1.875rem',
            '4xl': '2.25rem',
            '5xl': '3rem',
            '6xl': '3.75rem',
            '7xl': '4.5rem',
            '8xl': '6rem',
            '9xl': '8rem',
            '10xl': '10rem',
        },
        rotate: {
            '-180': '-180deg',
            '-90': '-90deg',
            '-45': '-45deg',
            '0': '0',
            '45': '45deg',
            '90': '90deg',
            '135': '135deg',
            '180': '180deg',
            '225': '225deg',
            '270': '270deg',
        },
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [forms],
};
