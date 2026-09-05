<?php

namespace Dynart\Dpress\Plugin;

/**
 * A plugin that contributes nothing, to inherit from and override the parts you want
 *
 * Extending this rather than implementing the interface means a plugin adding one field type is
 * four lines, and a method added to the interface later does not break every plugin in existence.
 */
abstract class AbstractPlugin implements PluginInterface {

    public function services(): array {
        return [];
    }

    public function controllers(): array {
        return [];
    }

    public function entities(): array {
        return [];
    }

    public function migrations(): array {
        return [];
    }

    public function widgets(): array {
        return [];
    }

    public function blocks(): array {
        return [];
    }

    public function pageAssets(): array {
        return [];
    }
    public function shortcodes(): array { return []; }

    public function permissions(): array {
        return [];
    }

    public function views(): array {
        return [];
    }

    public function assets(): array {
        return [];
    }

    public function register(): void {}
}
