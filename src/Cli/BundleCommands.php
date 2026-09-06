<?php

namespace Dynart\Dpress\Cli;

use Dynart\Dpress\DpressException;
use Dynart\Dpress\Service\BlockService;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\SiteBundle;
use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;

/**
 * `dpress export` and `dpress import`
 *
 * The rerender lives here rather than in `SiteBundle` for the reason the split always is: the
 * service knows what a bundle *is*, and rebuilding every stored document is a thing the site does
 * to itself afterwards. It is not optional, though - see `import()` below.
 */
class BundleCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected SiteBundle $bundle,
        protected ContentService $content,
        protected BlockService $blocks,
    ) {
        parent::__construct($output);
    }

    /**
     * `dpress export -to ./bundle`
     *
     * A folder, not an archive: `tar` and `zip` both exist already, and requiring one as a PHP
     * extension to save a word is a dependency forever.
     */
    public function export(array $params = []): int {
        $to = $this->param($params, 'to');
        if ($to === '') {
            return $this->fail('Where to? `dpress export -to ./bundle`');
        }
        try {
            $result = $this->bundle->export($to);
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        $manifest = $result['manifest'];
        foreach ($manifest['tables'] as $table => $count) {
            $this->output->writeLine('  '.str_pad($table, 28).$count.' row(s)');
        }
        $this->output->writeLine('  '.str_pad('uploads', 28)
            .$manifest['uploads']['files'].' file(s), '.$this->humanSize($manifest['uploads']['bytes']));
        $this->output->writeLine('');
        $this->note('Rendered for '.($manifest['content_rendered_for'] ?: 'nowhere recorded').'.');
        $this->note('`dpress.ini` is not in the bundle: it holds the password and the signing secret.');
        return $this->success('Exported '.$result['rows'].' row(s) to '.$result['path'].'.');
    }

    /**
     * `dpress import -from ./bundle -confirm [-force]`
     *
     * **`-confirm` is required** because this replaces every row the site has. Not a prompt: a
     * command that stops to ask cannot be run from a deploy script, and the flag is the same
     * answer given in advance.
     */
    public function import(array $params = []): int {
        $from = $this->param($params, 'from');
        if ($from === '') {
            return $this->fail('Where from? `dpress import -from ./bundle -confirm`');
        }
        if (!$this->flag($params, 'confirm')) {
            return $this->fail(
                'This replaces every row in the database and every file in uploads/. '
                .'Add -confirm when that is what you want.'
            );
        }
        try {
            $result = $this->bundle->import($from, $this->flag($params, 'force'));
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }

        foreach ($result['loaded'] as $table => $count) {
            $this->output->writeLine('  '.str_pad($table, 28).$count.' row(s)');
        }
        $this->output->writeLine('  '.str_pad('uploads', 28)
            .$result['files'].' file(s), '.$this->humanSize($result['bytes']));
        $this->output->writeLine('');

        // Not offered, not suggested - done. Stored HTML holds absolute URLs, so a site restored
        // under a new address points at the old one on every link, and nothing about the page
        // says so. A step that can be forgotten in that position is a step that will be.
        if ($result['moved']) {
            $this->note('The address moved: '.($result['from_url'] ?: 'unknown').' -> '.$result['to_url']);
        }
        $count = $this->content->rerenderAll();
        $blocks = $this->blocks->rerenderAll();
        $this->bundle->recordRenderAddress();
        $this->output->writeLine('  Re-rendered '.$count.' item(s) and '.$blocks.' block(s) for '.$result['to_url'].'.');
        $this->output->writeLine('');

        $this->reportDifferences($result['manifest']);
        $this->reportSkipped($result['skipped']);
        return $this->success('Imported. Run `dpress doctor` to check what is left.');
    }

    /**
     * A plugin's own table cannot exist on the first pass, and that is an ordering fact
     *
     * A plugin's migrations are registered when the loader runs it, the loader runs the plugins
     * named in `dp_setting`, and that row **arrives with this import** - so on a fresh site the
     * first run boots with no plugins, has nowhere to put their rows, and says so. The second run
     * boots with them enabled, `install()` creates their tables and the rows land.
     *
     * Two passes rather than a plugin loader that reloads itself mid-command: the loader also
     * registers migrations, form widgets, shortcodes and blocks into a container that has already
     * been built, and re-running it inside one process is a much larger promise than "run it
     * again" is a cost.
     */
    protected function reportSkipped(array $skipped): void {
        if (empty($skipped)) {
            return;
        }
        foreach ($skipped as $table) {
            $this->warn($table.' is in the bundle and has no table here yet.');
        }
        $this->note('Those belong to plugins, whose tables are made when the plugin is loaded - and');
        $this->note('the list of enabled plugins only arrived just now. Clone any that are missing,');
        $this->note('then run this import once more: the second pass boots with them on.');
    }

    /**
     * What the bundle expected the site to have, and does not
     *
     * A plugin enabled with nothing on disk is skipped **silently** at runtime, which is right -
     * a missing clone should not take a site down - and is exactly why the one moment it can be
     * said out loud is here, while somebody is looking at a terminal having just moved a site.
     */
    protected function reportDifferences(array $manifest): void {
        $plugins = (array)($manifest['plugins'] ?? []);
        if (!empty($plugins)) {
            $this->note('The bundle had these plugins enabled: '.join(', ', $plugins).'.');
            $this->note('Clone the ones that are missing into plugins/ - `dpress doctor` names them.');
        }
        $theme = (string)($manifest['theme'] ?? '');
        if ($theme !== '') {
            $this->note('The theme is `'.$theme.'`; it has to be in themes/ or the site falls back.');
        }
    }

    protected function note(string $message): void {
        $this->output->setColor(CliOutput::DARK_YELLOW);
        $this->output->writeLine('  '.$message);
        $this->output->setColor(null);
    }

    protected function warn(string $message): void {
        $this->output->setColor(CliOutput::YELLOW);
        $this->output->writeLine('  ! '.$message);
        $this->output->setColor(null);
    }

    protected function humanSize(int $bytes): string {
        $units = ['B', 'KB', 'MB', 'GB'];
        $at = 0;
        $size = (float)$bytes;
        while ($size >= 1024 && $at < count($units) - 1) {
            $size /= 1024;
            $at++;
        }
        return ($at === 0 ? (string)(int)$size : number_format($size, 1)).' '.$units[$at];
    }
}
