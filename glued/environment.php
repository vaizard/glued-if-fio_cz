<?php
declare(strict_types=1);

// Ensure $HTTP_RAW_POST_DATA is deprecated warning does not appear
ini_set('always_populate_raw_post_data','-1');
$settings = $container->get('settings');

error_reporting(E_ALL);
ini_set('display_errors', $settings['slim']['displayErrorDetails'] ? 'true' : 'false');
ini_set('display_startup_errors', $settings['slim']['displayErrorDetails'] ? 'true' : 'false');
date_default_timezone_set($settings['glued']['timezone']);

