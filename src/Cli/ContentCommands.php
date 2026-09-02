<?php

namespace Dynart\Dpress\Cli;

use Dynart\Micro\CliOutput;
use Dynart\Micro\CliOutputInterface;
use Dynart\Dpress\DpressException;
use Dynart\Dpress\Entity\Content;
use Dynart\Dpress\Service\ContentHistoryService;
use Dynart\Dpress\Service\ContentService;
use Dynart\Dpress\Service\UserService;

/**
 * Content from the console
 *
 * The admin UI arrives in a later phase, so this is how content gets created and inspected until
 * then - and `content:history` stays useful afterwards, since the audit tables are otherwise
 * invisible.
 */
class ContentCommands extends AbstractCommands {

    public function __construct(
        CliOutputInterface $output,
        protected ContentService $content,
        protected ContentHistoryService $history,
        protected UserService $users,
    ) {
        parent::__construct($output);
    }

    /**
     * `dpress content:create -title "Hello" -author admin@example.com [-type page] [-file body.md] [-publish]`
     */
    public function create(array $params = []): int {
        $title = $this->param($params, 'title');
        if ($title === '') {
            return $this->fail('A -title is required.');
        }
        $author = $this->resolveAuthor($this->param($params, 'author'));
        if ($author === null) {
            return $this->fail('An -author email is required, and it has to exist.');
        }
        $markdown = $this->readMarkdown($params);
        if ($markdown === null) {
            return $this->fail('Could not read the -file.');
        }
        try {
            $content = $this->content->create([
                'type'     => $this->param($params, 'type', Content::TYPE_POST),
                'title'    => $title,
                'slug'     => $this->param($params, 'slug'),
                'markdown' => $markdown,
                'status'   => $this->flag($params, 'publish') ? Content::STATUS_PUBLISHED : Content::STATUS_DRAFT,
            ], $author->id);
        } catch (DpressException $e) {
            return $this->fail($e->getMessage());
        }
        return $this->success("Created {$content->type} #{$content->id} at /{$content->slug} ({$content->status}).");
    }

    /**
     * `dpress content:list [-type post] [-status draft] [-search x]`
     */
    public function list(array $params = []): int {
        $context = [];
        foreach (['type', 'status', 'search'] as $key) {
            $value = $this->param($params, $key);
            if ($value !== '') {
                $context[$key] = $value;
            }
        }
        $rows = $this->content->findAll($context);
        if (empty($rows)) {
            $this->output->writeLine('No content.');
            return 0;
        }
        foreach ($rows as $row) {
            $this->output->setColor(CliOutput::CYAN);
            $this->output->write(str_pad('#'.$row['id'], 6));
            $this->output->setColor(null);
            $this->output->write(str_pad($row['type'], 6));
            $this->output->write(str_pad($row['status'], 11));
            $this->output->write(str_pad('/'.$row['slug'], 34));
            $this->output->writeLine((string)$row['title']);
        }
        $this->output->writeLine(count($rows).' item(s).');
        return 0;
    }

    /**
     * `dpress content:publish -id 1 [-unpublish]`
     */
    public function publish(array $params = []): int {
        $content = $this->content->findById((int)($params['id'] ?? 0));
        if ($content === null) {
            return $this->fail('No content with that -id.');
        }
        if ($this->flag($params, 'unpublish')) {
            $this->content->unpublish($content);
            return $this->success("#{$content->id} is a draft again.");
        }
        $this->content->publish($content);
        return $this->success("#{$content->id} is published.");
    }

    /**
     * `dpress content:delete -id 1`
     */
    public function delete(array $params = []): int {
        $content = $this->content->findById((int)($params['id'] ?? 0));
        if ($content === null) {
            return $this->fail('No content with that -id.');
        }
        $this->content->delete($content);
        return $this->success("Deleted #{$content->id}. Its history is kept.");
    }

    /**
     * `dpress content:history -id 1`
     *
     * The audit tables are invisible from every screen, so this is the only way to see that the
     * history is being written at all.
     */
    public function history(array $params = []): int {
        $id = (int)($params['id'] ?? 0);
        if ($id === 0) {
            return $this->fail('An -id is required.');
        }
        $revisions = $this->history->revisions($id);
        if (empty($revisions)) {
            $this->output->writeLine('No history for #'.$id.'.');
            return 0;
        }
        $this->output->writeLine(count($revisions).' revision(s) of #'.$id.', newest first:');
        $previous = null;
        foreach ($revisions as $revision) {
            $this->output->setColor($this->colorFor($revision['rev_type']));
            $this->output->write('  '.str_pad($revision['rev_type'], 5));
            $this->output->setColor(null);
            $this->output->write(str_pad('rev '.$revision['rev_id'], 10));
            $this->output->write(str_pad((string)$revision['rev_at'], 22));
            $this->output->writeLine((string)($revision['rev_user_name'] ?? 'system'));
            $previous = $revision;
        }
        $this->showLastDiff($revisions);
        return 0;
    }

    /**
     * `dpress content:rerender`
     */
    public function rerender(array $params = []): int {
        $count = $this->content->rerenderAll();
        return $this->success("Re-rendered $count item(s).");
    }

    /**
     * `dpress content:prune [-days 7]`
     *
     * Throws away the rows "New" made that nobody ever saved. A tidy-up rather than a necessity:
     * an author gets one auto-draft per type and clicking New again reuses it, so these are
     * bounded whether this ever runs or not - which is why there is no cron and no default
     * schedule, just a command for somebody who wants the table clean.
     *
     * Attachments go with them, because `delete()` is what does the removing.
     */
    public function pruneDrafts(array $params = []): int {
        // through `param()`, because a declared parameter that was not given arrives as an empty
        // string rather than as nothing, and `?? 7` never fires on one
        $days = (int)$this->param($params, 'days', '7');
        if ($days < 1) {
            return $this->fail('-days has to be at least 1.');
        }
        $before = date('Y-m-d H:i:s', strtotime("-$days days"));
        $count = $this->content->pruneAutoDrafts($before);
        return $this->success(
            $count === 0
                ? "No unsaved drafts older than $days day(s)."
                : "Removed $count unsaved draft(s) older than $days day(s)."
        );
    }

    protected function showLastDiff(array $revisions): void {
        if (count($revisions) < 2) {
            return;
        }
        $diff = $this->history->diff($revisions[1], $revisions[0]);
        if (empty($diff)) {
            return;
        }
        $this->output->writeLine('');
        $this->output->writeLine('Changed in the newest revision: '.join(', ', array_keys($diff)));
    }

    protected function colorFor(string $revType): int {
        return match ($revType) {
            'add' => CliOutput::GREEN,
            'del' => CliOutput::RED,
            default => CliOutput::DARK_YELLOW,
        };
    }

    protected function resolveAuthor(string $email): ?\Dynart\Dpress\Entity\User {
        if ($email === '') {
            return null;
        }
        return $this->users->findByEmail($this->users->normalizeEmail($email));
    }

    /**
     * Reads the markdown from -file, or from -markdown, or leaves it empty
     */
    protected function readMarkdown(array $params): ?string {
        $file = $this->param($params, 'file');
        if ($file !== '') {
            if (!is_file($file)) {
                return null;
            }
            return (string)file_get_contents($file);
        }
        return $this->param($params, 'markdown');
    }

}
