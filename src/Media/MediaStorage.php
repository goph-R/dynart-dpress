<?php

namespace Dynart\Dpress\Media;

use Dynart\Micro\ConfigInterface;
use Dynart\Dpress\Content\Slugger;
use Dynart\Dpress\DpressException;

/**
 * Where the files go, and what they are called
 *
 * **Write once.** A path is never reused: replacing a file produces a new path and a new
 * `Media` row, so an old revision that references the previous one keeps showing what it showed
 * then. Overwriting in place would rewrite history silently, and the audit would record nothing
 * because no column changed.
 *
 * Names are the slug of the original filename plus a short random suffix:
 * `2026/08/my-photo-a1b2c3.jpg`. The suffix is random rather than a hash of the contents, so
 * uploading the same bytes twice gives two items - no surprise deduplication.
 */
class MediaStorage {

    const CONFIG_PATH = 'media.path';
    const CONFIG_URL = 'media.url';

    /** Relative to the site root, inside the document root so Apache can serve it directly */
    const DEFAULT_PATH = '~/public/uploads';

    /** Relative to `app.base_url` */
    const DEFAULT_URL = '/uploads';

    /** Long enough that a collision needs about 16 million uploads in one month */
    const SUFFIX_BYTES = 3;

    public function __construct(
        protected ConfigInterface $config,
        protected Slugger $slugger,
    ) {}

    public function basePath(): string {
        return rtrim($this->config->getFullPath(
            (string)$this->config->get(self::CONFIG_PATH, self::DEFAULT_PATH)
        ), '/\\');
    }

    public function baseUrl(): string {
        return rtrim((string)$this->config->get(self::CONFIG_URL, self::DEFAULT_URL), '/');
    }

    /**
     * The absolute path of a stored file
     */
    public function fullPath(string $relativePath): string {
        return $this->basePath().'/'.ltrim($relativePath, '/');
    }

    /**
     * Picks a free relative path for a new file
     *
     * @param string $fileName What it was called when it was uploaded
     * @param string $extension The extension the sniffed mime type maps to, so the name matches
     *                          the bytes rather than whatever the uploader typed
     */
    public function reservePath(string $fileName, string $extension): string {
        $base = $this->slugger->slugify(pathinfo($fileName, PATHINFO_FILENAME));
        if ($base === '') {
            $base = 'file';
        }
        $base = mb_substr($base, 0, 80);
        $directory = gmdate('Y/m');
        do {
            $relative = $directory.'/'.$base.'-'.$this->randomSuffix().'.'.$extension;
        } while (file_exists($this->fullPath($relative)));
        return $relative;
    }

    /**
     * Moves an uploaded file into place
     *
     * @throws DpressException if the directory cannot be made or the file cannot be moved
     */
    public function store(string $sourcePath, string $relativePath, bool $isUpload = true): void {
        $target = $this->fullPath($relativePath);
        $this->makeDirectory(dirname($target));
        $moved = $isUpload
            ? @move_uploaded_file($sourcePath, $target)
            : @rename($sourcePath, $target);
        if (!$moved) {
            throw new DpressException('Could not store the uploaded file.');
        }
        @chmod($target, 0644);
    }

    public function exists(string $relativePath): bool {
        return is_file($this->fullPath($relativePath));
    }

    public function delete(string $relativePath): bool {
        $path = $this->fullPath($relativePath);
        return is_file($path) && @unlink($path);
    }

    /**
     * The derivative path of a stored file: `my-photo-a1b2c3.jpg` -> `my-photo-a1b2c3-thumb.jpg`
     */
    public function derivativePath(string $relativePath, string $preset): string {
        $extension = pathinfo($relativePath, PATHINFO_EXTENSION);
        $withoutExtension = substr($relativePath, 0, -(strlen($extension) + 1));
        return $withoutExtension.'-'.$preset.'.'.$extension;
    }

    /**
     * Creates the uploads folder and the .htaccess that keeps it from executing anything
     *
     * The folder is inside the document root, so without this an uploaded `.php` would be a
     * remote shell. The `.svg` rule is the second lock behind the sanitiser: a strict CSP stops
     * scripts running when somebody navigates straight to the file. An SVG used as `<img src>`
     * is a non-scripted context regardless.
     *
     * **Every directive is inside an `<IfModule>`**, and that is not tidiness. Apache does not
     * skip a directive it does not recognise - it refuses to serve the directory at all, with a
     * 500 and `Invalid command` in the log. `php_flag` belongs to mod_php, so under PHP-FPM this
     * file turned every image on the site into a server error; `Header` belongs to mod_headers,
     * which is not enabled everywhere either. Both are now no-ops when the module is absent.
     *
     * Losing `php_flag` on FPM costs nothing, because the `<FilesMatch>` below is the actual
     * lock: it refuses to serve those files at all, so there is nothing left to interpret.
     *
     * @param bool $force Rewrite a file that is already there - for `dpress media:protect`,
     *                    since an installation that got the old one needs a way to be fixed
     */
    public function protect(bool $force = false): void {
        $base = $this->basePath();
        $this->makeDirectory($base);
        $path = $base.'/.htaccess';
        if (is_file($path) && !$force) {
            return;
        }
        file_put_contents($path, self::PROTECTION);
    }

    /**
     * What `protect()` writes. A constant so a test can read it without touching a disk.
     */
    const PROTECTION = <<<'HTACCESS'
# Uploaded files are data, never code. Written by dpress - `dpress media:protect` rewrites it.
#
# Every directive is inside an <IfModule> on purpose: Apache does not ignore one it does not
# know, it answers 500 for the whole directory. `php_flag` is mod_php, so without a guard this
# file turns every image into a server error under PHP-FPM.

<IfModule mod_php.c>
    php_flag engine off
</IfModule>
<IfModule mod_php7.c>
    php_flag engine off
</IfModule>
<IfModule mod_php5.c>
    php_flag engine off
</IfModule>

# The real lock, and the one that works whatever PHP is running as: these are not served at all,
# so there is nothing left to interpret.
<FilesMatch "\.(php|phtml|php[0-9]|phar|pl|py|cgi|asp|aspx|sh|htaccess)$">
    Require all denied
</FilesMatch>

# SVGs are sanitised on the way in. This stops anything that got past the sanitiser - or that
# predates it - from running when the file is opened directly.
<IfModule mod_headers.c>
    <FilesMatch "\.svg$">
        Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>
HTACCESS;

    protected function makeDirectory(string $path): void {
        if (is_dir($path)) {
            return;
        }
        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new DpressException("Could not create the directory '$path'.");
        }
    }

    protected function randomSuffix(): string {
        return bin2hex(random_bytes(self::SUFFIX_BYTES));
    }
}
