<?php
error_reporting(E_ALL);
require_once dirname(__DIR__) . '/vendor/autoload.php';

use jimbocoder\Newrelic\Plugin\PHPOPcache;

$plugin = new PHPOPcache(array(

    // Options are scalars ...
    'pollCycle' => 120,

    // ... or callables if convenient.
    'licenseKey' => function() { return getValueFromSomeConfigurationSubsystem('newrelic.licenseKey.example'); },

    // One possibility:
    'instanceName' => function() { return $_SERVER['HTTP_HOST']; },

));

if (php_sapi_name() == 'cli' && !isset($_REQUEST['live'])) {
    echo "Please run this code via a web browser. The OPcache statistics will not be accurate if ran from the command line!\n";

    die();
}

if (isset($_REQUEST['test'])) {
    echo "Success! Newrelic-phpopcache appears to be installed successfully.\n";
    die();
}

$plugin->run();

