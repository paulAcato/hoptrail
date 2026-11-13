import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

/**
 * React and @wordpress/* are provided by WordPress at runtime (see admin/class-assets.php
 * DEPENDENCIES); everything else is bundled into one classic (IIFE) script, no ES modules.
 */
const globals: Record<string, string> = {
	react: 'React',
	'react-dom': 'ReactDOM',
	'react/jsx-runtime': 'ReactJSXRuntime',
	'@wordpress/element': 'wp.element',
	'@wordpress/components': 'wp.components',
	'@wordpress/api-fetch': 'wp.apiFetch',
	'@wordpress/i18n': 'wp.i18n',
	'@wordpress/a11y': 'wp.a11y',
	'@wordpress/primitives': 'wp.primitives',
	'@wordpress/date': 'wp.date',
	'@wordpress/hooks': 'wp.hooks',
};

export default defineConfig({
	plugins: [react({ jsxRuntime: 'automatic' })],
	base: './',
	build: {
		outDir: 'dist',
		emptyOutDir: true,
		// dist/manifest.json, not dist/.vite/: hidden files are not allowed in a WordPress.org plugin.
		manifest: 'manifest.json',
		target: 'es2022',
		cssCodeSplit: false,
		// Not minified: translate.wordpress.org and `wp i18n make-pot` extract strings from the
		// shipped JS, and need intact __() / _n() / sprintf calls with quoted strings.
		minify: false,
		rollupOptions: {
			input: 'assets/scripts/main.tsx',
			external: Object.keys(globals),
			output: {
				format: 'iife',
				globals,
				entryFileNames: 'hoptrail.[hash].js',
				assetFileNames: 'hoptrail.[hash][extname]',
			},
		},
	},
});
