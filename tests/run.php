<?php

/**
 * SPDX-FileCopyrightText: 2026 Israel Guido
 * SPDX-License-Identifier: OSL-3.0
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

$root = dirname(__DIR__, 3);
require $root . '/vendor/autoload.php';

Mage::app();

$failures = 0;
foreach (glob(__DIR__ . '/*Test.php') as $test) {
    try {
        require $test;
        echo basename($test) . ": OK\n";
    } catch (Throwable $e) {
        $failures++;
        fwrite(STDERR, basename($test) . ': ' . $e->getMessage() . "\n");
    }
}

exit($failures === 0 ? 0 : 1);
