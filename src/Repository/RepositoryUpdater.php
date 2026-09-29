<?php

namespace Dynart\Dpress\Repository;

/**
 * Brings a git clone up to date - the Docs plugin's source before a build (moved here from it in 0.93.0)
 *
 * What somebody would otherwise type on the server - `git pull` in the folder, then the
 * submodules - so a push to the repository reaches the site with nothing more than a button, or
 * a command from a cron job.
 *
 * **Nothing from a request reaches the command.** The folder is the caller's - the Docs plugin's
 * is a setting only an administrator can change - the arguments are fixed here, and the command is an argument list rather than a
 * string handed to a shell, so no character in a folder name is ever interpreted.
 *
 * **`--ff-only`**: the server's copy is a mirror, not a place anybody works. A pull that cannot
 * fast-forward - somebody edited a file on the server - fails and says so, rather than making a
 * merge commit there that the next pull would have to live with.
 *
 * **It cannot hang a request.** A prompt for a password would wait for ever on a web request, so
 * git is told there is nobody to ask (`GIT_TERMINAL_PROMPT=0`), and a connection that slows to
 * almost nothing is given up after half a minute.
 */
class RepositoryUpdater {

    /** How much of git's own output a failure reports - its last lines are the ones that say why */
    const OUTPUT_LINES = 4;

    /**
     * @return array{ok: bool, message: string, before?: string, after?: string} one line either way -
     *         what changed, or why not - and, when it worked, the commit before and after, for a
     *         caller that says it in words of its own
     */
    public function update(string $folder): array {
        if (!Git::available()) {
            return self::failed('PHP cannot run git here: proc_open is disabled.');
        }
        if (!Git::isClone($folder)) {
            return self::failed("$folder is not a git clone.");
        }
        [, $before] = $this->git($folder, ['rev-parse', '--short', 'HEAD']);
        foreach ([
            ['pull', '--ff-only', '--recurse-submodules'],
            ['submodule', 'update', '--init', '--recursive'],
        ] as $arguments) {
            [$code, $output] = $this->git($folder, $arguments);
            if ($code !== 0) {
                return self::failed('git '.$arguments[0].' failed: '.self::tail($output));
            }
        }
        [, $after] = $this->git($folder, ['rev-parse', '--short', 'HEAD']);
        $before = trim($before);
        $after = trim($after);
        return [
            'ok'      => true,
            'before'  => $before,
            'after'   => $after,
            'message' => $before === $after
                ? "It was up to date, at $after."
                : "It was updated from $before to $after.",
        ];
    }

    /** One git command in the folder - see `Git` */
    protected function git(string $folder, array $arguments): array {
        return (new Git())->run($folder, $arguments);
    }

    /**
     * Why git failed, on one line: its `fatal:` and `error:` lines when it printed any, or else
     * its last few lines - never its `hint:` lines, which are advice about configuring git and
     * crowd out the one line that says what went wrong
     */
    public static function tail(string $output): string {
        $lines = array_values(array_filter(
            array_map('trim', preg_split('/\r\n|\r|\n/', $output)),
            fn(string $line): bool => $line !== '' && !str_starts_with($line, 'hint:')
        ));
        $errors = array_values(array_filter($lines, fn(string $line): bool => preg_match('/^(fatal|error):/', $line) === 1));
        $tail = implode(' ', array_slice($errors !== [] ? $errors : $lines, -self::OUTPUT_LINES));
        return $tail !== '' ? $tail : 'it printed nothing.';
    }

    protected static function failed(string $message): array {
        return ['ok' => false, 'message' => $message];
    }
}
