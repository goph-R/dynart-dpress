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
     * remote shell. The `.svg` rule is the cheap half of the SVG story until the sanitiser
     * lands: a strict CSP stops scripts running when somebody navigates straight to the file.
     * An SVG used as `<img src>` never ran scripts anyway.
     */
    public function protect(): void {
        $base = $this->basePath();
        $this->makeDirectory($base);
        $path = $base.'/.htaccess';
        if (is_file($path)) {
            return;
        }
        file_put_contents($path, <<<'HTACCESS'
# Uploaded files are data, never code.
php_flag engine off
<FilesMatch "\.(php|phtml|php[0-9]|phar|pl|py|cgi|asp|aspx|sh|htaccess)$">
    Require all denied
</FilesMatch>

# SVGs are sanitised on the way in, so nothing executable should reach this folder. This is the
# second lock: it stops anything that got past the sanitiser - or that predates it - from running
# when the file is opened directly. An <img src> is a non-scripted context regardless.
<FilesMatch "\.svg$">
    Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
    Header set X-Content-Type-Options "nosniff"
</FilesMatch>
HTACCESS);
    }

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
