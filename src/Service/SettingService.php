<?php

namespace Dynart\Dpress\Service;

use Dynart\Micro\ConfigInterface;
use Dynart\Micro\EventServiceInterface;
use Dynart\Micro\Entities\Database;
use Dynart\Micro\Entities\EntityManager;
use Dynart\Dpress\Entity\Setting;

/**
 * The settings an editor may change while the site runs
 *
 * Read constantly - every page asks for the site name and the theme - so the whole table is
 * loaded once per request and kept in memory. It is a handful of rows.
 *
 * A value falls back to `dpress.ini` when the database has none, so a fresh install works
 * before anybody has saved anything, and an operator can still pin something in the config.
 */
class SettingService {

    const EVENT_UPDATED = 'setting:updated';

    /** Config keys are the setting name under a `dpress.` prefix: `dpress.site_name` */
    const CONFIG_PREFIX = 'dpress.';

    private ?array $values = null;

    public function __construct(
        protected ConfigInterface $config,
        protected EntityManager $em,
        protected Database $db,
        protected EventServiceInterface $events,
    ) {}

    public function get(string $name, mixed $default = null): mixed {
        $values = $this->all();
        if (array_key_exists($name, $values) && $values[$name] !== null && $values[$name] !== '') {
            return $values[$name];
        }
        return $this->config->get(self::CONFIG_PREFIX.$name, $default);
    }

    public function getBool(string $name, bool $default = false): bool {
        $value = $this->get($name, $default);
        if (is_bool($value)) {
            return $value;
        }
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    public function getInt(string $name, int $default = 0): int {
        $value = $this->get($name, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    /**
     * @return array Everything stored in the database, in [name => value] format
     */
    public function all(): array {
        if ($this->values === null) {
            $this->values = [];
            foreach ($this->db->fetchAll('select `name`, `value` from '.$this->em->safeTableName(Setting::class)) as $row) {
                $this->values[$row['name']] = $row['value'];
            }
        }
        return $this->values;
    }

    public function set(string $name, mixed $value): void {
        $setting = $this->em->findById(Setting::class, $name);
        if (!$setting instanceof Setting) {
            $setting = new Setting();
            $setting->name = $name;
        }
        $setting->value = $value === null ? null : (string)$value;
        $setting->updated_at = gmdate('Y-m-d H:i:s');
        $this->em->save($setting);
        $this->values = null; // reload on the next read
        $this->events->emit(self::EVENT_UPDATED, [$name, $setting->value]);
    }

    /**
     * Is this stored in the database, as opposed to falling back to the config?
     */
    public function isStored(string $name): bool {
        return array_key_exists($name, $this->all());
    }

    public function forget(): void {
        $this->values = null;
    }
}
