<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\LoggerInterface;

/**
 * Which settings the Settings screen writes, and how each is read back
 *
 * A registry, like `FormWidgets`, `Shortcodes` and `Blocks`, and for a sharper reason than any of
 * them. This was a `const FIELDS` on `SettingsAdminController`, and `save()` iterated it - so a
 * plugin could add a field to the settings form through `form.admin_settings:created`, see it
 * render, fill it in, press Save and watch it silently not be written. **An extension point that
 * appears to work is worse than one that is missing**: the missing one sends somebody looking for
 * another way, and this one sent them looking for a bug in their own code.
 *
 * A field is a **name and a type**. The type is how the value is read out of the database and
 * written back into it, and there are four:
 *
 * | | |
 * |---|---|
 * | `string` | trimmed and stored as typed |
 * | `bool` | `'1'` or `'0'`, so an unticked box is off rather than absent |
 * | `int` | cast, stored as digits |
 * | `media` | an id, and nothing chosen is stored as nothing rather than as `0` |
 *
 * **The form field is optional and separate.** Core's twelve are built by hand in
 * `AdminForms::settings()` because most of them need something only the controller can fetch - the
 * list of themes, of timezones, of code themes, the thumbnail of the chosen logo. A plugin's has
 * nowhere to get that from and nothing to fetch, so it passes its field definition here and the
 * form builder appends it: one call, and the setting renders, saves and is audited.
 */
class SettingFields {

    const TYPES = ['string', 'bool', 'int', 'media'];

    /** @var array<string, array{type: string, field: array}> */
    private array $fields = [];

    public function __construct(protected LoggerInterface $logger) {}

    /**
     * @param string $type  one of `TYPES`; anything else is refused and logged rather than stored,
     *                      because a type nobody handles means a setting that reads back as
     *                      whatever `match` falls through to
     * @param array $field  the form field definition, or `[]` for one the settings form builds
     *                      itself. The same shape `Form::addFields()` takes.
     */
    public function add(string $name, string $type = 'string', array $field = []): void {
        if (!in_array($type, self::TYPES, true)) {
            $this->logger->warning(
                "dpress: the setting '$name' asked for the type '$type'. Known: ".join(', ', self::TYPES)
            );
            return;
        }
        $this->fields[$name] = ['type' => $type, 'field' => $field];
    }

    public function has(string $name): bool {
        return isset($this->fields[$name]);
    }

    /**
     * @return array<string, string> name => type, which is what saving and reading back need
     */
    public function types(): array {
        return array_map(fn(array $field) => $field['type'], $this->fields);
    }

    /**
     * The ones that brought their own form field, in registration order
     *
     * @return array<string, array> name => field definition
     */
    public function formFields(): array {
        return array_filter(array_map(fn(array $field) => $field['field'], $this->fields));
    }
}
