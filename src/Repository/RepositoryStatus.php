<?php

namespace Dynart\Dpress\Repository;

/**
 * Which files of a git clone are changed here and not on the remote
 *
 * What an editor in the admin warns about (moved here from the Docs plugin in 0.93.0): a file saved from the admin is changed in the server's clone and
 * nowhere else. Until it is committed and pushed it is one `git checkout` - or a fresh clone - away
 * from gone, and it can stop the next pull.
 *
 * Two states, per file, relative to the folder with `/`:
 *
 * | | |
 * |---|---|
 * | `uncommitted` | changed in the working tree - `git status` |
 * | `unpushed` | committed here, in commits the remote does not have |
 *
 * **Every repository in the folder**, the submodules too: a file of a submodule - the Docs
 * plugin's `dos-game-engine/` in `docs-public` - has its status asked of that submodule, not of
 * the clone around it, which only sees "the submodule changed". The remote is the branch's upstream, or `origin/HEAD` for a submodule
 * checked out at a commit rather than a branch, which is how `git submodule update` leaves one.
 *
 * **As few git commands as it can**, since each is a process started: one `git status
 * --porcelain=v2 --branch` per repository answers both "what is changed" and "is the branch ahead
 * of its upstream"; the files of unpushed commits are asked for only when there are some, and
 * `.gitmodules` is read here rather than by git. A clean clone with three submodules is four
 * commands, plus one for each submodule checked out at a commit.
 */
class RepositoryStatus {

    const UNCOMMITTED = 'uncommitted';
    const UNPUSHED = 'unpushed';

    /** Submodules inside submodules, this deep and no deeper */
    const MAX_DEPTH = 3;

    public function __construct(protected Git $git) {}

    /**
     * @return array<string, string>|null path => state, or null when the folder is not a git
     *                                     clone (or git cannot be run) and there is nothing to say
     */
    public function changes(string $folder): ?array {
        if ($folder === '' || !Git::available() || !Git::isClone($folder)) {
            return null;
        }
        $changes = [];
        $this->collect($folder, '', $changes, 0);
        ksort($changes);
        return $changes;
    }

    protected function collect(string $root, string $prefix, array &$changes, int $depth): void {
        // the working tree and the branch in one: the submodules are asked on their own, below
        [$code, $output] = $this->git->run($root, ['status', '--porcelain=v2', '--branch', '-z', '--untracked-files=no', '--ignore-submodules=all']);
        if ($code === 0) {
            $status = self::parseStatus($output);
            foreach ($status['paths'] as $path) {
                $changes[$prefix.$path] = self::UNCOMMITTED;
            }
            foreach ($this->unpushed($root, $status) as $path) {
                $changes[$prefix.$path] ??= self::UNPUSHED;
            }
        }
        if ($depth < self::MAX_DEPTH) {
            foreach (self::submodules($root) as $path) {
                $this->collect($root.'/'.$path, $prefix.$path.'/', $changes, $depth + 1);
            }
        }
    }

    /**
     * The files of commits here that the remote does not have
     *
     * On a branch, `git status` already said how far ahead of its upstream it is, so nothing more
     * is run when that is nothing. A submodule is checked out at a commit, with no branch and no
     * upstream: `origin/HEAD` stands in, and one count says whether there is anything to list.
     *
     * @return string[]
     */
    protected function unpushed(string $root, array $status): array {
        if ($status['upstream'] !== null) {
            $remote = $status['upstream'];
            if ($status['ahead'] === 0) {
                return [];
            }
        } else {
            [$code, $output] = $this->git->run($root, ['rev-list', '--count', 'origin/HEAD..HEAD']);
            if ($code !== 0 || (int)trim($output) === 0) {
                return [];
            }
            $remote = 'origin/HEAD';
        }
        // `A...B`: what B has since it and A parted - the commits here, not the remote's
        [$code, $output] = $this->git->run($root, ['diff', '--name-only', '-z', $remote.'...HEAD']);
        return $code === 0 ? array_values(array_filter(explode("\0", $output), 'strlen')) : [];
    }

    /** @return string[] the submodules checked out here, as paths relative to the repository */
    public static function submodules(string $root): array {
        if (!is_file($root.'/.gitmodules')) {
            return [];
        }
        preg_match_all('/^\s*path\s*=\s*(.+?)\s*$/m', (string)file_get_contents($root.'/.gitmodules'), $matches);
        return array_values(array_filter($matches[1], fn(string $path) => Git::isClone($root.'/'.$path)));
    }

    /**
     * What `git status --porcelain=v2 --branch -z` says: the changed paths, and the branch
     *
     * Headers are `# branch.upstream origin/main` and `# branch.ab +2 -0`. A changed file is
     * `1 <XY> <sub> <mH> <mI> <mW> <hH> <hI> <path>`; a rename is `2` with one field more and is
     * followed by the path it was renamed from; a conflict is `u` with two more.
     *
     * @return array{paths: string[], upstream: ?string, ahead: int}
     */
    public static function parseStatus(string $output): array {
        $paths = [];
        $upstream = null;
        $ahead = 0;
        $entries = explode("\0", $output);
        for ($i = 0; $i < count($entries); $i++) {
            $entry = $entries[$i];
            if (str_starts_with($entry, '# branch.upstream ')) {
                $upstream = substr($entry, 18);
            } else if (preg_match('/^# branch\.ab \+(\d+) /', $entry, $ab) === 1) {
                $ahead = (int)$ab[1];
            } else if (str_starts_with($entry, '1 ')) {
                $paths[] = explode(' ', $entry, 9)[8] ?? '';
            } else if (str_starts_with($entry, '2 ')) {
                $paths[] = explode(' ', $entry, 10)[9] ?? '';
                $i++;   // the path it was renamed from, which is not a file here any more
            } else if (str_starts_with($entry, 'u ')) {
                $paths[] = explode(' ', $entry, 11)[10] ?? '';
            }
        }
        return ['paths' => array_values(array_filter($paths, 'strlen')), 'upstream' => $upstream, 'ahead' => $ahead];
    }
}
