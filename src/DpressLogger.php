<?php

namespace Dynart\Dpress;

use Dynart\Micro\Logger;

/**
 * The framework's logger with a default that is not a URL
 *
 * `Logger::DEFAULT_DIR` is the relative `logs`, resolved against the working directory - and
 * the working directory of a web request is wherever the entry script lives, which for a dpress
 * site is `public/`. So an installation that configured no log directory wrote its errors,
 * their stack traces, their absolute paths and their bound SQL parameters into the document
 * root, and served them to anybody who asked for `/logs/log_2026-08-05.txt`.
 *
 * `~/logs` is the site root, which is one level above what Apache serves. A site that wants
 * them somewhere else sets `log.dir`; a site that sets nothing is still safe, which is the
 * point - the dangerous option should not be the one you get by saying nothing.
 */
class DpressLogger extends Logger {

    const DEFAULT_DIR = '~/logs';
}
