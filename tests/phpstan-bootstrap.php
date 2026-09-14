<?php

// Konstanten, die das Plugin selbst (churchtools-plugin.php) oder WordPress in
// wp-config.php definiert - PHPStan fuehrt keins von beiden aus.
define('CTP_VERSION', '0.0.0');
define('CTP_PLUGIN_FILE', __DIR__ . '/../churchtools-plugin.php');
define('CTP_PLUGIN_DIR', dirname(__DIR__) . '/');
define('CTP_PLUGIN_URL', 'https://example.org/wp-content/plugins/churchtools-plugin/');
