#!/usr/bin/env node
import { promises as fs } from 'node:fs';
import path from 'node:path';
import sharp from 'sharp';

const ROOT = path.resolve(import.meta.dirname, '..');
const TARGET_DIR = path.join(ROOT, 'assets/images/templates-images');

async function walk(dir) {
	const out = [];
	for (const entry of await fs.readdir(dir, { withFileTypes: true })) {
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) out.push(...(await walk(full)));
		else if (entry.isFile() && /\.png$/i.test(entry.name)) out.push(full);
	}
	return out;
}

const files = await walk(TARGET_DIR);
let beforeTotal = 0;
let afterTotal = 0;

for (const file of files) {
	const before = (await fs.stat(file)).size;
	beforeTotal += before;

	const buf = await sharp(file)
		.png({ quality: 80, compressionLevel: 9, palette: true, effort: 10 })
		.toBuffer();

	if (buf.length < before) {
		await fs.writeFile(file, buf);
		afterTotal += buf.length;
		const pct = ((1 - buf.length / before) * 100).toFixed(0);
		console.log(`${path.relative(ROOT, file)}: ${(before / 1024).toFixed(0)}K → ${(buf.length / 1024).toFixed(0)}K (-${pct}%)`);
	} else {
		afterTotal += before;
		console.log(`${path.relative(ROOT, file)}: skipped (already smaller)`);
	}
}

const savedMB = ((beforeTotal - afterTotal) / 1024 / 1024).toFixed(2);
const pct = ((1 - afterTotal / beforeTotal) * 100).toFixed(0);
console.log(`\nTotal: ${(beforeTotal / 1024 / 1024).toFixed(2)} MB → ${(afterTotal / 1024 / 1024).toFixed(2)} MB (saved ${savedMB} MB, -${pct}%)`);
