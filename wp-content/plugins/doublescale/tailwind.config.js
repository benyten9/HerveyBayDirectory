/** @type {import('tailwindcss').Config} */
module.exports = {
	darkMode: ['class'],
	content: [
		'./src/api/email-editor-blocks/built-in-blocks/text/**/*.tsx',
		'./src/client/pages/**/*.ts',
		'./src/client/pages/**/*.tsx',
		'./src/components/**/*.tsx',
		'./src/shared/**/*.{ts,tsx}',
		'./src/builder/**/*.tsx',
		'./src/renderer/**/*.{ts,tsx}',
	],
	theme: {
		container: {
			center: true,
			padding: '2rem',
			screens: {
				'2xl': '1400px',
			},
		},
		extend: {
			zIndex: {
				popover: '160000',
			},
			fontFamily: {
				sans: ['"Inter"', 'sans-serif'],
			},
			backgroundImage: {
				'sidebar-accent':
					'linear-gradient(90deg, #1E3A8A 61.06%, #3B82F6 100%)',
			},
			colors: {
				border: 'hsl(var(--border))',
				input: 'hsl(var(--input))',
				ring: 'hsl(var(--ring))',
				background: 'hsl(var(--background))',
				foreground: 'hsl(var(--foreground))',
				primary: {
					DEFAULT: 'hsl(var(--primary))',
					foreground: 'hsl(var(--primary-foreground))',
				},
				secondary: {
					DEFAULT: 'hsl(var(--secondary))',
					foreground: 'hsl(var(--secondary-foreground))',
					background: 'hsl(var(--secondary-background))',
				},
				tertiary: {
					DEFAULT: 'hsl(var(--tertiary))',
					foreground: 'hsl(var(--tertiary-foreground))',
				},
				destructive: {
					DEFAULT: 'hsl(var(--destructive))',
					foreground: 'hsl(var(--destructive-foreground))',
				},
				muted: {
					DEFAULT: 'hsl(var(--muted))',
					foreground: 'hsl(var(--muted-foreground))',
				},
				accent: {
					DEFAULT: 'hsl(var(--accent))',
					foreground: 'hsl(var(--accent-foreground))',
				},
				popover: {
					DEFAULT: 'hsl(var(--popover))',
					foreground: 'hsl(var(--popover-foreground))',
				},
				card: {
					DEFAULT: 'hsl(var(--card))',
					foreground: 'hsl(var(--card-foreground))',
				},
				chart: {
					1: 'hsl(var(--chart-1))',
					2: 'hsl(var(--chart-2))',
					3: 'hsl(var(--chart-3))',
					4: 'hsl(var(--chart-4))',
					5: 'hsl(var(--chart-5))',
				},
				sidebar: {
					DEFAULT: 'hsl(var(--sidebar-background))',
					foreground: 'hsl(var(--sidebar-foreground))',
					primary: 'hsl(var(--sidebar-primary))',
					'primary-foreground':
						'hsl(var(--sidebar-primary-foreground))',
					accent: 'hsl(var(--sidebar-accent))',
					'accent-foreground':
						'hsl(var(--sidebar-accent-foreground))',
					border: 'hsl(var(--sidebar-border))',
					ring: 'hsl(var(--sidebar-ring))',
					header: 'hsl(var(--sidebar-header))',
				},
				// Defaults to #3A3A99; white-label addons recolor every variant
				// (incl. opacity modifiers) by overriding --ds-brand-primary
				// with an "R G B" triplet.
				brandPrimary: 'rgb(var(--ds-brand-primary, 58 58 153) / <alpha-value>)',
				'color-primary': '#953AE4',
				'color-secondary': '#F1E0FF',
				'color-tertiary': '#FBF9FC',
				'color-primary-text': '#292D32',
				'color-lime-green': '#B7F005',
			},
			borderRadius: {
				lg: 'var(--radius)',
				md: 'calc(var(--radius) - 2px)',
				sm: 'calc(var(--radius) - 4px)',
			},
			keyframes: {
				'accordion-down': {
					from: {
						height: '0',
					},
					to: {
						height: 'var(--radix-accordion-content-height)',
					},
				},
				'accordion-up': {
					from: {
						height: 'var(--radix-accordion-content-height)',
					},
					to: {
						height: '0',
					},
				},
				shimmer: {
					'0%': {
						transform: 'translateX(-100%)',
					},
					'100%': {
						transform: 'translateX(100%)',
					},
				},
			},
			animation: {
				'accordion-down': 'accordion-down 0.2s ease-out',
				'accordion-up': 'accordion-up 0.2s ease-out',
				shimmer: 'shimmer 2s infinite',
			},
		},
	},
	safelist: [
		'doublescale-input-control',
		'doublescale-textarea-control',
		'doublescale-radio-card',
		'bg-secondary',
		'border-primary',
		// Gray
		'bg-gray-200',
		'text-gray-600',
		'dark:bg-gray-200',
		'dark:text-gray-600',
		'bg-gray-300',
		'text-gray-700',
		'dark:bg-gray-300',
		'dark:text-gray-700',

		// Green
		'bg-green-100',
		'text-green-600',
		'dark:bg-green-100',
		'dark:text-green-600',
		'bg-green-200',
		'text-green-700',
		'dark:bg-green-200',
		'dark:text-green-700',

		// Blue
		'bg-blue-100',
		'text-blue-600',
		'dark:bg-blue-100',
		'dark:text-blue-600',

		// Amber
		'bg-amber-100',
		'text-amber-600',
		'dark:bg-amber-100',
		'dark:text-amber-600',

		// Fuchsia
		'bg-fuchsia-100',
		'text-fuchsia-600',
		'dark:bg-fuchsia-100',
		'dark:text-fuchsia-600',

		// Orange
		'bg-orange-100',
		'text-orange-600',
		'dark:bg-orange-100',
		'dark:text-orange-600',

		// Red
		'bg-red-100',
		'text-red-600',
		'dark:bg-red-100',
		'dark:text-red-600',
	],
	plugins: [require('tailwindcss-animate')],
};
