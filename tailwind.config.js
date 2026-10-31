/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ['./templates/**/*.twig'],
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Roboto Condensed"', 'sans-serif'],
        serif: ['"Instrument Serif"', 'serif'],
      },
      colors: {
        primary: '#3498db',
        'primary-dark': '#2980b9',
        secondary: '#2c3e50',
        tertiary: '#34495e',
      },
    },
  },
  plugins: [],
};
