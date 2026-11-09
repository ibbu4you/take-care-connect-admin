/*
 | Copies the Inter woff2 files into public/fonts/inter.
 |
 | app.blade.php declares the @font-face rules inline against stable /fonts/...
 | URLs rather than importing them from the package, so that the same page can
 | <link rel="preload"> the exact same paths — and so the URLs resolve against
 | Laravel's origin in development too. (Declared in app.css they resolved
 | against the Vite dev server instead, which does not serve public/, and every
 | font request 404'd until a production build.)
 |
 | Only the weights the blade file declares are copied: shipping the whole
 | package would put hundreds of unused weights and subsets in a public web
 | root. Runs on npm install (postinstall) and before every build. Idempotent.
 */
import { cp, mkdir, rm, access } from 'node:fs/promises';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const source = join(root, 'node_modules', '@fontsource', 'inter', 'files');
const destination = join(root, 'public', 'fonts', 'inter');

const weights = [400, 500, 600, 700];
const files = weights.map((weight) => `inter-latin-${weight}-normal.woff2`);

try {
    await access(source);
} catch {
    console.error('@fontsource/inter is not installed — run `npm install` first.');
    process.exit(1);
}

// Removed rather than merged, so a package upgrade that renames a file cannot
// leave the old one behind to be picked up by a stale cache.
await rm(destination, { recursive: true, force: true });
await mkdir(destination, { recursive: true });

// The previous family. Cleared so a stale copy is not served from public/.
await rm(join(root, 'public', 'fonts', 'dm-sans'), { recursive: true, force: true });

for (const file of files) {
    await cp(join(source, file), join(destination, file));
}

console.log(`Inter published to public/fonts/inter (${files.length} files)`);
