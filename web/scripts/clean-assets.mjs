import { rmSync } from 'node:fs'
import { fileURLToPath } from 'node:url'

/**
 * Clear the built assets before a build.
 *
 * Vite would normally do this with emptyOutDir, and it must not: the output
 * directory is `public/`, which also holds `index.php` and `.htaccess` — both
 * committed, both hand-written, and a build that emptied it would take the API
 * and the routing rules with it.
 *
 * So the cleaning is narrowed to the one directory that is entirely generated.
 * Without it, every build leaves its fingerprinted files behind: 247 files and
 * 5.9 MB had accumulated where 24 belong, all of them committed, because a
 * changed file is a changed name and nothing ever removed the old name.
 *
 * `index.html` is left alone deliberately. Its name never changes, so the build
 * overwrites it rather than adding to it, and deleting it first would leave a
 * checkout with no front page if the build then failed.
 */
const assets = fileURLToPath(new URL('../../public/assets', import.meta.url))

rmSync(assets, { recursive: true, force: true })
