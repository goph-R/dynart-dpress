<?php

namespace Dynart\Dpress\Plugin;

/**
 * One plugin found on disk, and how it went
 *
 * A value object rather than the plugin itself: the admin screen has to be able to list something
 * that is enabled but missing, or present but broken, and neither of those has an instance to
 * ask. `status()` is the whole vocabulary the screen needs.
 */
class Plugin {

    /** On disk, not enabled */
    const STATUS_AVAILABLE = 'available';

    /** Enabled and loaded */
    const STATUS_ENABLED = 'enabled';

    /** Enabled, but loading it threw - the reason is in `error` */
    const STATUS_FAILED = 'failed';

    /** Enabled in the settings, and no longer on disk */
    const STATUS_MISSING = 'missing';

    public function __construct(
        public readonly string $name,
        public readonly string $path = '',
        public readonly array $manifest = [],
        public string $status = self::STATUS_AVAILABLE,
        public string $error = '',
        public ?PluginInterface $instance = null,
    ) {}

    public function title(): string {
        return (string)($this->manifest['title'] ?? $this->name);
    }

    public function version(): string {
        return (string)($this->manifest['version'] ?? '');
    }

    public function author(): string {
        return (string)($this->manifest['author'] ?? '');
    }

    public function description(): string {
        return (string)($this->manifest['description'] ?? '');
    }

    /** The PSR-4 prefix its classes live under, e.g. `Acme\ContactForm\` */
    public function namespacePrefix(): string {
        return trim((string)($this->manifest['namespace'] ?? ''), '\\').'\\';
    }

    /** The class implementing `PluginInterface` */
    public function className(): string {
        return trim((string)($this->manifest['class'] ?? ''), '\\');
    }

    public function isOnDisk(): bool {
        return $this->path !== '';
    }

    public function isLoaded(): bool {
        return $this->status === self::STATUS_ENABLED && $this->instance !== null;
    }

    /**
     * Marks it as broken and says why
     *
     * The message reaches an admin screen, so it is the exception's rather than a generic one:
     * "Class Acme\Analytics\Plugin not found" is a fixable sentence, "failed to load" is not.
     */
    public function fail(string $error): void {
        $this->status = self::STATUS_FAILED;
        $this->error = $error;
        $this->instance = null;
    }
}
