/** @type {import('tailwindcss').Config} */
const freeTailwind = require('../doublescale/tailwind.config.js');

module.exports = {
	presets: [freeTailwind],
	darkMode: ['class'],
	content: [
		'./src/api/email-editor-blocks/built-in-blocks/text/**/*.tsx',
		'./src/client/pages/**/*.tsx',
		'./src/components/**/*.tsx',
		'./src/builder/**/*.tsx',
		'../doublescale/src/**/*.{ts,tsx}',
		// Subscriptions admin pages live in the sibling plugin now; Pro's
		// stylesheet is the host stylesheet whenever Pro is active, so its
		// Tailwind scan must include their classes or they get purged. Folder
		// case MUST match disk exactly (case-sensitive on Linux/CI).
		'../DoubleScale-Subscriptions/src/**/*.{ts,tsx}',
	],
	theme: {
		extend: {
			zIndex: {
				popover: '160000',
				taskDialogMenu: '1800004',
			},
			fontFamily: {
				sans: ['"Inter"', 'sans-serif'],
			},
			colors: {
				// Defaults to #3A3A99; white-label addons recolor every variant
				// (incl. opacity modifiers) by overriding --ds-brand-primary
				// with an "R G B" triplet.
				brandPrimary: 'rgb(var(--ds-brand-primary, 58 58 153) / <alpha-value>)',
				primaryText: '#29292E',
				'color-primary': '#953AE4',
				'color-secondary': '#F1E0FF',
				'color-tertiary': '#FBF9FC',
				'color-primary-text': '#292D32',
				'color-lime-green': '#B7F005',
			},
		},
	},
};
