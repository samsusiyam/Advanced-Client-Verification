<?php

/**
 * PHPUnit bootstrap for Advanced Client Verification.
 * Loads Composer autoloader (PSR-4 for ClientVerification\ namespace) plus the
 * procedural helper functions (cv_*, autoloader, encryption helpers) normally
 * provided by the WHMCS runtime.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Helpers/functions.php';
