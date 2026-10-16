<?php

require __DIR__.'/../vendor/autoload.php';

$database = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? '');

if (! is_string($database) || ! str_ends_with($database, '_test')) {
    fwrite(STDERR, "\nRefusing to run tests: DB_DATABASE must end with _test (got: ".($database === '' ? 'not set' : $database).").\n");
    exit(1);
}
