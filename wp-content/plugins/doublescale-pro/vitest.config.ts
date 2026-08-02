/// <reference types="vitest" />
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

const srcDir = path.resolve(__dirname, 'src');
const freePluginDir = path.resolve(__dirname, '../doublescale');

export default defineConfig({
	plugins: [react()],
	resolve: {
		alias: {
			// Pro-side aliases — mirror the most-used entries from tsconfig.json.
			// Order matters: `/ui` and `/config` must precede the bare
			// `@doublescale/components` prefix, as in webpack.config.js.
			'@doublescale/components/ui': path.join(freePluginDir, 'src/shared/ui'),
			'@doublescale/components': path.join(srcDir, 'components'),
			// Pro ships its own config module; webpack.config.js resolves this to
			// `__dirname/src/config`, not the free plugin's. The two have diverged
			// (unknown-slug defaults differ), so tests must exercise Pro's copy.
			'@doublescale/config': path.join(srcDir, 'config'),
			'@doublescale/hooks': path.join(freePluginDir, 'src/hooks'),
			'@doublescale/shared/icons': path.join(freePluginDir, 'src/shared/icons'),
			'@doublescale-free/hooks/use-custom-fields': path.join(
				freePluginDir,
				'src/client/hooks/use-customFields.ts'
			),
			'@doublescale/shared': path.join(srcDir, 'shared'),
			'@doublescale/email-sequences-page': path.join(srcDir, 'client/pages/email-sequences/index.tsx'),
			'@/lib/stage-sortable-id': path.join(srcDir, 'lib/stage-sortable-id.ts'),
			'@/lib/group-by-stage': path.join(srcDir, 'lib/group-by-stage.ts'),
			'@/lib/utils': path.join(freePluginDir, 'src/shared/lib/utils.ts'),
			'@/lib': path.join(srcDir, 'shared/lib'),
			'@/hooks/use-board-view-mode': path.join(srcDir, 'hooks/use-board-view-mode.ts'),
			'@/hooks/use-board-dnd-sensors': path.join(
				srcDir,
				'hooks/use-board-dnd-sensors.ts'
			),
			'@/hooks/use-debounce': path.join(srcDir, 'hooks/use-debounce.ts'),
			'@/hooks/booking/notice': path.join(freePluginDir, 'src/hooks/booking/notice/index.ts'),
			'@/hooks': path.join(srcDir, 'shared/hooks'),
			'@/components/ui': path.join(freePluginDir, 'src/shared/ui'),
			'@/components': path.join(srcDir, 'components'),
			'@/utils': path.join(srcDir, 'shared/utils'),
			'@/services': path.join(srcDir, 'shared/services'),
			'@/stores': path.join(srcDir, 'stores'),
			'@/client': path.join(srcDir, 'client'),

			'@pro/client': path.join(srcDir, 'client'),
			'@pro': srcDir,
			// Tests for those imports require the Free plugin to live at ../doublescale.
			'@doublescale-free': path.join(freePluginDir, 'src'),
		},
	},
	test: {
		environment: 'jsdom',
		globals: true,
		setupFiles: ['./tests/frontend/setup.ts'],
		include: ['src/**/*.{test,spec}.{ts,tsx}', 'tests/frontend/**/*.{test,spec}.{ts,tsx}'],
		exclude: [
			'node_modules/**',
			'build/**',
			'dependencies/**',
			'tests/e2e/**',
		],
		coverage: {
			provider: 'v8',
			reporter: ['text', 'html', 'lcov'],
			include: ['src/**/*.{ts,tsx}'],
			exclude: [
				// Heavy editor / graph code — defer to E2E (same exclusions as Free).
				'src/builder/blocks/**',
				'src/client/pages/automation/**',
				'src/client/pages/campaign/**',
				'src/client/pages/booking/**',
				'src/client/pages/pipelines/**',
				'src/renderer/**',
				'src/**/*.d.ts',
				'src/**/index.{ts,tsx}',
				'src/types/**',
			],
		},
	},
});
