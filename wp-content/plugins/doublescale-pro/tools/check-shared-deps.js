#!/usr/bin/env node
/**
 * Compares dependency versions that must stay aligned between the Free and Pro
 * plugins (Pro compiles `../doublescale/src` and must resolve the same library graph).
 *
 * Usage: node tools/check-shared-deps.js
 * Exit 1 if any shared key has a different version string.
 */

/* eslint-disable no-console */
const fs = require('fs');
const path = require('path');

/** Versions that must match so Pro’s bundle of Free source shares one dependency graph. */
const SHARED_CRITICAL_DEPS = [
	'react',
	'react-dom',
	'react-router-dom',
	'react-hook-form',
	'framer-motion',
	'@tanstack/react-table',
	'lexical',
	'@lexical/react',
	'@wordpress/data',
	'@wordpress/element',
];

const freeJson = path.resolve(__dirname, '../../doublescale/package.json');
const proJson = path.resolve(__dirname, '../package.json');

function readDeps(p) {
	const j = JSON.parse(fs.readFileSync(p, 'utf8'));
	return {
		...j.dependencies,
		...j.devDependencies,
	};
}

const free = readDeps(freeJson);
const pro = readDeps(proJson);
const sharedKeys = SHARED_CRITICAL_DEPS.filter(
	(k) =>
		Object.prototype.hasOwnProperty.call(free, k) &&
		Object.prototype.hasOwnProperty.call(pro, k)
);

const missing = SHARED_CRITICAL_DEPS.filter(
	(k) =>
		!Object.prototype.hasOwnProperty.call(free, k) ||
		!Object.prototype.hasOwnProperty.call(pro, k)
);
if (missing.length) {
	console.warn(
		'check-shared-deps: warning — critical key missing in one package.json:',
		missing.join(', ')
	);
}

const mismatches = [];
for (const key of sharedKeys.sort()) {
	if (free[key] !== pro[key]) {
		mismatches.push({ key, free: free[key], pro: pro[key] });
	}
}

if (mismatches.length) {
	console.error(
		'check-shared-deps: version mismatch between DoubleScale and doublescale-pro:\n'
	);
	for (const m of mismatches) {
		console.error(
			`  ${m.key}:\n    free: ${m.free}\n    pro:  ${m.pro}\n`
		);
	}
	process.exit(1);
}

console.log(
	`check-shared-deps: OK (${sharedKeys.length} overlapping package entries match).`
);
