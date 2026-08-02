/**
 * Playwright E2E tests against a real WordPress admin with BOTH DoubleScale
 * (free) and DoubleScale Pro active.
 *
 * Run (from this plugin root, after `npm install`):
 *   npm run e2e:install     # browsers once
 *   npm run e2e             # all projects, or: npm run e2e:smoke
 *   npm run e2e:ui          # interactive UI mode
 *
 * Prereqs: WordPress reachable at the same URL the tests use. Defaults below
 * match wp-env's tests port (Pro uses 8891 to avoid colliding with Free on 8889).
 * Override with:
 *   WP_BASE_URL=http://localhost:8891 npm run e2e
 *
 * Login for global-setup (`tests/e2e/global-setup.ts`):
 *   WP_ADMIN_USER / WP_ADMIN_PASS (default admin / password)
 *
 * Composer (delegates to npm): `composer test:e2e`
 */
import { defineConfig, devices } from '@playwright/test';

const BASE_URL = process.env.WP_BASE_URL ?? 'http://localhost:8891';

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
