<?php

namespace Dynart\Dpress\Migration;

use Dynart\Micro\Entities\MigrationInterface;
use Dynart\Micro\Entities\QueryExecutor;
use Dynart\Dpress\Entity\AuthAttempt;

/**
 * Creates the table the rate limiter counts in
 *
 * Without an audit mirror, unlike everything else here: the rows expire, and a history of them
 * would outlive the thing it is a history of - turning a rate limiter into a permanent record of
 * what people typed into a login form.
 */
class CreateAuthAttemptTable implements MigrationInterface {

    public function __construct(
        private QueryExecutor $queryExecutor,
    ) {}

    public function version(): string {
        return '0007_create_auth_attempt_table';
    }

    public function up(): void {
        $this->queryExecutor->createTable(AuthAttempt::class, true);
    }
}
