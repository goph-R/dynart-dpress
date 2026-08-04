<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Setting;
use Dynart\Dpress\Service\MenuService;
use Dynart\Dpress\Service\SettingService;
use Dynart\Dpress\Theme\ThemeService;

/**
 * Themes, menus and settings from the console
 */
class ThemeCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected ThemeService $themes,
        protected MenuService $menus,
        protected SettingService $settings,
    ) {
        parent::__construct($output);
    }

    /**
     * `dpress theme:list`
     */
    public function listThemes(array $params = []): int {
        $themes = $this->themes->all();
        $active = $this->themes->active();
        $this->output->writeLine('Themes in '.$this->themes->path().':');
        if (empty($themes)) {
            $this->output->writeLine('  none - a theme is a folder with a theme.ini in it');
            return 0;
        }
        foreach ($themes as $name => $theme) {
            $isActive = $name === $active;
            $this->output->setColor($isActive ? CliOutput::GREEN : CliOutput::CYAN);
            $this->output->write('  '.str_pad(($isActive ? '* ' : '  ').$name, 18));
            $this->output->setColor(null);
            $this->output->write(str_pad($theme['title'], 24));
            $this->output->writeLine($theme['places'] ? 'places: '.join(', ', array_keys($theme['places'])) : '');
        }
        if ($active === ThemeService::FALLBACK) {
            $this->output->writeLine('No theme is active, so the built in templates render.');
        }
        return 0;
    }

    /**
     * `dpress theme:set -name mytheme` (an empty name goes back to the built in templates)
     */
    public function setTheme(array $params = []): int {
        $name = $this->param($params, 'name');
        try {
            $this->themes->activate($name);
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success($name === ''
            ? 'Switched back to the built in templates.'
            : "The site now renders with '$name'.");
    }

    /**
     * `dpress menu:list`
     */
    public function listMenus(array $params = []): int {
        $menus = $this->menus->menus();
        if (empty($menus)) {
            $this->output->writeLine('No menus.');
            return 0;
        }
        $places = $this->themes->places();
        foreach ($menus as $row) {
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write(str_pad('#'.$row['id'], 6));
            $this->output->setColor(null);
            $this->output->write(str_pad($row['name'], 20));
            $place = (string)$row['place'];
            if ($place === '') {
                $this->output->writeLine('(not placed)');
            } else if ($places !== [] && !array_key_exists($place, $places)) {
                $this->output->writeLine($place.'  (the active theme has no such place)');
            } else {
                $this->output->writeLine($place);
            }
            $this->printItems($this->menus->tree($place), 2);
        }
        return 0;
    }

    /**
     * `dpress setting:list`
     */
    public function listSettings(array $params = []): int {
        $stored = $this->settings->all();
        $names = [Setting::THEME, Setting::SITE_NAME, Setting::SITE_DESCRIPTION,
                  Setting::REGISTRATION_OPEN, Setting::POSTS_PER_PAGE];
        foreach (array_keys($stored) as $name) {
            if (!in_array($name, $names)) {
                $names[] = $name;
            }
        }
        foreach ($names as $name) {
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write(str_pad($name, 22));
            $this->output->setColor(null);
            $value = $this->settings->get($name);
            $this->output->write(str_pad($value === null || $value === '' ? '-' : (string)$value, 26));
            $this->output->writeLine($this->settings->isStored($name) ? '' : '(from dpress.ini)');
        }
        return 0;
    }

    /**
     * `dpress setting:set -name site_name -value "My site"`
     */
    public function setSetting(array $params = []): int {
        $name = $this->param($params, 'name');
        if ($name === '') {
            return $this->fail('A -name is required.');
        }
        $this->settings->set($name, $this->param($params, 'value'));
        return $this->success("$name is now '".$this->param($params, 'value')."'.");
    }

    protected function printItems(array $items, int $indent): void {
        foreach ($items as $item) {
            $this->output->writeLine(str_repeat(' ', $indent + 4).'- '.$item['label'].'  '.$item['url']);
            $this->printItems($item['children'], $indent + 2);
        }
    }
}
