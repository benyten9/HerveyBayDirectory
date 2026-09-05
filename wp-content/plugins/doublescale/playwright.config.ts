/**
 * Playwright E2E tests against a real WordPress admin (plugin active).
 *
 * Run (from this plugin root, after `npm install`):
 *   npm run e2e:install     # browsers once
 *   npm run e2e           # all projects, or: npm run e2e:smoke
 *   npm run e2e:ui        # interactive UI mode
 *
 * Prereqs: WordPress reachable at the same URL the tests use. Defaults below
 * match a common wp-env-style port; override if yours differs:
 *   WP_BASE_URL=http://localhost:8888 npm run e2e
 *
 * Pipeline, Tasks, Email Sequences, and Forms UI tests (`pipeline-module.spec.ts`,
 * `tasks-module.spec.ts`, `email-sequences-module.spec.ts`, `forms-module.spec.ts`)
 * need DoubleScale Pro active (Forms also requires the forms module toggle);
 * without Pro they skip after the upgrade notice is shown.
 * `campaigns-module.spec.ts` covers email campaigns on any install; its SMS block skips
 * without the Pro SMS bridge.
 * `smtp-module.spec.ts` covers built-in SMTP (Connections + Logs); skips when the smtp
 * module toggle is off.
 * `booking-module.spec.ts` covers Booking (Calendars, Bookings list, Settings); skips when
 * the booking module toggle is off or booking capabilities are missing.
 *
 * Login for global-setup (`tests/e2e/global-setup.ts`):
 *   WP_ADMIN_USER / WP_ADMIN_PASS (default admin / password)
 *
 * Composer (delegates to npm): `composer test:e2e`
 */
import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright resolves `goto('wp-admin/...')` with the WHATWG URL API: the base
 * must end with `/` or the last path segment is dropped (e.g. base
 * `http://host/wordpress` + `wp-admin/` → `http://host/wp-admin/` → 404).
 */
function normalizeBaseURL(raw: string | undefined): string {
	const trimmed = typeof raw === 'string' ? raw.trim() : '';
	const fallback = 'http://localhost:8889';
	const base = trimmed === '' ? fallback : trimmed.replace(/\/+$/, '');
	return `${base}/`;
}

const BASE_URL = normalizeBaseURL(process.env.WP_BASE_URL);

export default defineConfig({
	testDir: './tests/e2e',
	testMatch: '**/*.spec.ts',
	timeout: 60_000,
	expect: { timeout: 10_000 },
	fullyParallel: false,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 2 : 0,
	workers: process.env.CI ? 1 : undefined,
	reporter: process.env.CI ? [['github'], ['html', { open: 'never' }]] : 'list',

	globalSetup: './tests/e2e/global-setup.ts',

	use: {
		baseURL: BASE_URL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
		video: 'retain-on-failure',
		storageState: 'tests/e2e/.auth/admin.json',
	},

	projects: [
		{
			name: 'smoke',
			testMatch: /smoke\.spec\.ts/,
			use: { ...devices['Desktop Chrome'] },
		},
		{
			name: 'chromium',
			testIgnore: /smoke\.spec\.ts/,
			use: { ...devices['Desktop Chrome'] },
		},
	],
});
