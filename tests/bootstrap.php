<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

$composer_autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

date_default_timezone_set('UTC');
