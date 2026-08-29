/*
 | Copies the DM Sans woff2 files into public/fonts/dm-sans.
 |
 | app.css declares the @font-face rules by hand against stable /fonts/... URLs
 | rather than importing them from the package, so that app.blade.php can
 | <link rel="preload"> the exact same paths. That only works if the files are
 | actually served from public/ — which is what this does.
 |
 | Only the three weights app.css declares are copied: shipping the whole
 | package would put ~200 files of unused weights and subsets in a public web
 | root. Runs on npm install (postinstall) and before every build. Idempotent.
 */
import { cp, mkdir, rm, access } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'node_modules', '@fontsource', 'dm-sans', 'files');
const destination = join(root, 'public', 'fonts', 'dm-sans');

const weights = [400, 500, 700];
const files = weights.map((weight) => `dm-sans-latin-${weight}-normal.woff2`);

try {
    await access(source);
} catch {
    console.error('@fontsource/dm-sans is not installed — run `npm install` first.');
    process.exit(1);
}

// Removed rather than merged, so a package upgrade that renames a file cannot
// leave the old one behind to be picked up by a stale cache.
await rm(destination, { recursive: true, force: true });
await mkdir(destination, { recursive: true });

for (const file of files) {
    await cp(join(source, file), join(destination, file));
}

console.log(`DM Sans published to public/fonts/dm-sans (${files.length} files)`);
