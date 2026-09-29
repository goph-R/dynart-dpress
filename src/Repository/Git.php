<?php

namespace Dynart\Dpress\Repository;

/**
 * One git command in a folder: its exit code and everything it printed
 *
 * Shared by `RepositoryUpdater` and `RepositoryStatus`, so there is one place that decides how git is
 * started: an argument list rather than a shell line, so nothing in a path is ever interpreted,
 * and nobody for git to ask for a password - `GIT_TERMINAL_PROMPT=0` - so a missing credential
 * fails at once instead of hanging a web request. A connection that slows to almost nothing is
 * given up after half a minute.
 */
class Git {

    /** Below this many bytes a second, for `LOW_SPEED_TIME` seconds, git gives up on a fetch */
    const LOW_SPEED_LIMIT = 1000;
    const LOW_SPEED_TIME = 30;

    public static function available(): bool {
        return function_exists('proc_open');
    }

    /** Is the folder the top of a clone - or of a submodule, whose `.git` is a file */
    public static function isClone(string $folder): bool {
        return file_exists($folder.'/.git');
    }

    /**
     * @return array{0: int, 1: string}
     */
    public function run(string $folder, array $arguments): array {
        $process = proc_open(
            array_merge(['git', '-C', $folder], $arguments),
            // one stream for both, so neither can fill up and stall git while the other is read
            [1 => ['pipe', 'w'], 2 => ['redirect', 1]],
            $pipes,
            $folder,
            self::environment()
        );
        if (!is_resource($process)) {
            return [-1, 'git could not be started.'];
        }
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    /** This process's own environment, and nobody to ask for a password */
    protected static function environment(): array {
        return array_merge(getenv(), [
            'GIT_TERMINAL_PROMPT'      => '0',
            'GIT_HTTP_LOW_SPEED_LIMIT' => (string)self::LOW_SPEED_LIMIT,
            'GIT_HTTP_LOW_SPEED_TIME'  => (string)self::LOW_SPEED_TIME,
        ]);
    }
}
