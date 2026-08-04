<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Media;
use Dynart\Dpress\Media\ImageProcessor;
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
        if ($this->looksLikeSvg($file)) {
            $this->warnAboutSvg();
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
        return $this->success("Imported #{$media->id} as {$media->path} ({$media->category}, {$this->media->humanSize($media->size)}).");
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
     * `dpress media:purge -id 1 -confirm`
     *
     * The one operation that actually removes bytes, so it asks to be meant.
     */
    public function purge(array $params = []): int {
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
        $this->media->purge($media);
        return $this->success("Purged #{$media->id}. The file is gone.");
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

    protected function looksLikeSvg(string $path): bool {
        return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
    }

    /**
     * The same warning the upload dialog shows, until the sanitiser lands
     */
    protected function warnAboutSvg(): void {
        $this->output->setColor(CliOutput::YELLOW);
        $this->output->writeLine('This is an SVG, and SVGs are not sanitised yet.');
        $this->output->setColor(null);
        $this->output->writeLine('An SVG is a document: it can carry scripts. Only upload one you trust.');
        $this->output->writeLine('Used through <img src> it cannot run scripts, and the uploads folder sends');
        $this->output->writeLine('a strict Content-Security-Policy for .svg, so opening it directly is covered too.');
        $this->output->writeLine('');
    }
}
