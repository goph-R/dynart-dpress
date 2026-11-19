<?php

namespace Dynart\Dpress;

/**
 * The logger of a command that has no site to log into (0.97.1)
 *
 * `init`, `help` and `version` run without a `dpress.ini` - writing one is what `init` is for.
 * The application builds its logger all the same, and `DpressLogger`'s default is `~/logs`,
 * where `~` is the site's root: with no config the root is empty and `~/logs` is **`/logs`, at
 * the top of the filesystem**. Run as root that worked, and left a `/logs` with an empty file in
 * it on the server; run as the web server's user it could not be made, and `dpress init` - the
 * first command anybody runs - died with a stack trace about `Logger::class`.
 *
 * There is nowhere such a command *should* keep a log, so it does not keep one: what it has to
 * say goes to the terminal it was typed in. `php://stderr` and not the working directory, because
 * `dpress help` typed in `/root` should not leave a `logs/` there either - the complaint
 * `bin/dpress` already makes about a command run outside a site.
 */
class ConsoleLogger extends DpressLogger {

    const DEFAULT_DIR = 'php://stderr';
}
