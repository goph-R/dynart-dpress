<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\ImageProcessor;
use Dynart\Dpress\Media\MediaStorage;
use Dynart\Dpress\Media\MediaTypes;
use Dynart\Dpress\Service\MediaService;
use Dynart\Dpress\Service\UserService;

/**
 * The media library from the console
 */
class MediaCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected MediaService $media,
        protected UserService $users,
        protected ImageProcessor $images,
        protected MediaStorage $storage,
    ) {
        parent::__construct($output);
    }

    /**
     * `dpress media:import -file photo.jpg -user admin@example.com [-alt "..."] [-title "..."]`
     */
    public function import(array $params = []): int {
        $file = $this->param($params, 'file');
        if ($file === '') {
            return $this->fail('A -file is required.');
        }
        $user = $this->users->findByEmail($this->users->normalizeEmail($this->param($params, 'user')));
        if ($user === null) {
            return $this->fail('A -user email is required, and it has to exist.');
        }
        try {
            $media = $this->media->importFile($file, $user->id, [
                'alt'     => $this->param($params, 'alt'),
                'title'   => $this->param($params, 'title'),
                'caption' => $this->param($params, 'caption'),
            ]);
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        $note = $media->mime_type === MediaTypes::SVG ? ' The SVG was sanitised on the way in.' : '';
        return $this->success(
            "Imported #{$media->id} as {$media->path} ({$media->category}, {$this->media->humanSize($media->size)})."
            .$note
        );
    }

    /**
     * `dpress media:list [-category image] [-search x] [-deleted]`
     */
    public function list(array $params = []): int {
        $context = [];
        foreach (['category', 'search'] as $key) {
            $value = $this->param($params, $key);
            if ($value !== '') {
                $context[$key] = $value;
            }
        }
        if ($this->flag($params, 'deleted')) {
            $context['with_deleted'] = true;
        }
        $rows = $this->media->findAll($context);
        if (empty($rows)) {
            $this->output->writeLine('No media.');
            return 0;
        }
        foreach ($rows as $row) {
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write(str_pad('#'.$row['id'], 6));
            $this->output->setColor(null);
            $this->output->write(str_pad($row['category'], 10));
            $this->output->write(str_pad($this->media->humanSize((int)$row['size']), 10));
            $this->output->write(str_pad((string)$row['path'], 40));
            $this->output->writeLine($row['deleted_at'] !== null ? '(deleted)' : '');
        }
        $this->output->writeLine(count($rows).' item(s).');
        return 0;
    }

    /**
     * `dpress media:delete -id 1 [-restore]`
     */
    public function delete(array $params = []): int {
        $media = $this->media->findById((int)($params['id'] ?? 0));
        if ($media === null) {
            return $this->fail('No media with that -id.');
        }
        if ($this->flag($params, 'restore')) {
            $this->media->restore($media);
            return $this->success("#{$media->id} is back in the library.");
        }
        $this->media->delete($media);
        $used = $this->media->usageCount($media->id);
        $this->success("#{$media->id} is marked deleted. The file is still on disk.");
        if ($used > 0) {
            $this->output->writeLine("It is still referenced by $used item(s).");
        }
        return 0;
    }

    /**
     * `dpress media:purge -id 1 -confirm`, or `dpress media:purge -all -confirm`
     *
     * The one operation that actually removes bytes, so it asks to be meant.
     *
     * `-all` is a flag rather than a `media:purge-all` of its own, because the command names
     * here are `group:action` with no punctuation in the action - and because the CLI already
     * says which of two things a command is doing with a flag: `media:delete -restore`,
     * `content:publish -unpublish`.
     */
    public function purge(array $params = []): int {
        if ($this->flag($params, 'all')) {
            return $this->purgeBin($params);
        }
        $media = $this->media->findById((int)($params['id'] ?? 0));
        if ($media === null) {
            return $this->fail('No media with that -id.');
        }
        $used = $this->media->usageCount($media->id);
        if (!$this->flag($params, 'confirm')) {
            $this->output->setColor(CliOutput::YELLOW);
            $this->output->writeLine('This deletes the file itself, not just the library entry.');
            $this->output->setColor(null);
            $this->output->writeLine("  #{$media->id}  {$media->path}");
            $this->output->writeLine("  referenced by $used item(s)");
            $this->output->writeLine('');
            $this->output->writeLine('Every revision of every post that shows this file will break, including');
            $this->output->writeLine('old ones, because the bytes will not exist any more.');
            $this->output->writeLine('');
            $this->output->writeLine('Add -confirm if that is what you want.');
            return 1;
        }
        $cleared = $this->media->purge($media);
        return $this->success("Purged #{$media->id}. The file is gone."
            .($cleared > 0 ? " $cleared post(s) lost their featured image." : ''));
    }

    /**
     * `-all`: **empties the bin**, and nothing else
     *
     * `media:delete` is what puts something in the bin, so this only ever removes files somebody
     * has already said they are finished with - which is what makes an all-at-once version of the
     * most destructive command in the CMS a reasonable thing to have at all. To be rid of a file
     * that is not in the bin, delete it first, or name it with `-id`.
     */
    protected function purgeBin(array $params): int {
        $rows = $this->binned();
        if ($rows === []) {
            $this->output->writeLine('The bin is empty.');
            return 0;
        }
        if (!$this->flag($params, 'confirm')) {
            $this->output->setColor(CliOutput::YELLOW);
            $this->output->writeLine('This deletes the files themselves, not just the library entries.');
            $this->output->setColor(null);
            foreach ($rows as $row) {
                $this->output->writeLine("  #{$row['id']}  {$row['path']}");
            }
            $this->output->writeLine('');
            $this->output->writeLine(count($rows).' item(s) in the bin. Every revision of every post that');
            $this->output->writeLine('shows one of these will break, including old ones.');
            $this->output->writeLine('');
            $this->output->writeLine('Add -confirm if that is what you want.');
            return 1;
        }
        $purged = 0;
        $cleared = 0;
        foreach ($rows as $row) {
            $media = $this->media->findById((int)$row['id']);
            if ($media === null) {
                continue;
            }
            $cleared += $this->media->purge($media);
            $purged++;
        }
        return $this->success("Purged $purged item(s)."
            .($cleared > 0 ? " $cleared post(s) lost their featured image." : ''));
    }

    /**
     * Everything in the bin
     *
     * `with_deleted` widens the list to *both*, which is right for a screen showing a bin toggle
     * and wrong here, so the ones that are still in the library are dropped again.
     */
    protected function binned(): array {
        return array_values(array_filter(
            $this->media->findAll(['with_deleted' => true]),
            fn(array $row): bool => !empty($row['deleted_at'])
        ));
    }

    /**
     * `dpress media:regenerate [-id 1]`
     *
     * Derivatives are a cache, so this only deletes them; the next request rebuilds what it needs.
     */
    public function regenerate(array $params = []): int {
        $id = (int)($params['id'] ?? 0);
        $media = $id > 0 ? $this->media->findById($id) : null;
        if ($id > 0 && $media === null) {
            return $this->fail('No media with that -id.');
        }
        $count = $this->media->clearDerivatives($media);
        $this->output->writeLine('Presets: '.join(', ', array_keys($this->images->presets())));
        return $this->success("Cleared $count derivative(s). They are rebuilt on the next request.");
    }

    /**
     * `dpress media:protect`
     *
     * Rewrites `uploads/.htaccess`, which is what stops an uploaded file from being executed.
     * It is written at install and left alone afterwards, so an installation that got an older
     * one has no other way to be brought up to date - and the older one 500s the whole folder
     * under PHP-FPM, because `php_flag` is a mod_php directive and Apache refuses a directive
     * it does not know rather than skipping it.
     *
     * Overwrites without asking, on purpose: this file belongs to dpress, and a site that has
     * edited it wants its own rules in the vhost, not here where an upload can rewrite them.
     */
    public function protect(array $params = []): int {
        $this->storage->protect(true);
        $this->output->writeLine($this->storage->basePath().'/.htaccess');
        return $this->success('Rewritten. Uploaded files cannot be executed.');
    }

    /**
     * `dpress media:sanitize [-id 1] [-confirm]`
     *
     * For a library that predates the sanitiser. Everything uploaded since is already clean, so
     * this is a one-off after an upgrade - and it is the **only** thing in the CMS that rewrites
     * a stored file. Write-once exists so a historical revision keeps showing the image it
     * showed; here the point is precisely that what a file used to contain must stop being
     * served, so it reports by default and needs `-confirm` to actually write.
     */
    public function sanitize(array $params = []): int {
        $id = (int)($params['id'] ?? 0);
        $items = $id > 0 ? array_filter([$this->media->findById($id)]) : $this->svgItems();
        if (empty($items)) {
            return $this->success('There are no SVGs in the library.');
        }
        $confirm = $this->flag($params, 'confirm');
        $changed = 0;
        $failed = 0;

        foreach ($items as $media) {
            if ($media->mime_type !== MediaTypes::SVG) {
                continue;
            }
            try {
                $dirty = $confirm ? $this->media->sanitizeStored($media) : $this->media->wouldSanitize($media);
            } catch (DpressException $e) {
                $failed++;
                $this->output->setColor(CliOutput::RED);
                $this->output->writeLine("  #{$media->id}  {$media->path} - {$e->getMessage()}");
                $this->output->setColor(null);
                continue;
            }
            if (!$dirty) {
                continue;
            }
            $changed++;
            $this->output->writeLine(($confirm ? '  cleaned  ' : '  would change  ')."#{$media->id}  {$media->path}");
        }

        if ($failed > 0) {
            $this->output->writeLine('');
            $this->output->writeLine('The failed ones could not be parsed as SVG at all. Look at them by hand.');
        }
        if ($changed === 0) {
            return $this->success('Every SVG in the library is already clean.');
        }
        if ($confirm) {
            return $this->success("Rewrote $changed file(s) in place.");
        }
        $this->output->writeLine('');
        $this->output->setColor(CliOutput::YELLOW);
        $this->output->writeLine("$changed file(s) contain something the sanitiser would remove.");
        $this->output->setColor(null);
        $this->output->writeLine('Rewriting them changes what those URLs serve, for every revision that shows');
        $this->output->writeLine('them - which is the point, but it is not reversible. Add -confirm to do it.');
        return 1;
    }

    /**
     * @return Media[] Every SVG in the library, deleted ones included - the bytes are still there
     */
    protected function svgItems(): array {
        $result = [];
        foreach ($this->media->findAll(['with_deleted' => true]) as $row) {
            if ($row['mime_type'] !== MediaTypes::SVG) {
                continue;
            }
            $media = $this->media->findById((int)$row['id']);
            if ($media !== null) {
                $result[] = $media;
            }
        }
        return $result;
    }
}
