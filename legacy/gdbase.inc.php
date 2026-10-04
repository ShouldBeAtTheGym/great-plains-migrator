<?php

require_once __DIR__ . '/includes/settings.php';

$serverName = setting('gp_server');
$connectionInfo = array(
    "Database" => setting('gp_database'),
    "UID" => setting('gp_user'),
    "PWD" => setting('gp_password'),
    "TrustServerCertificate" => setting('gp_trust_cert', '0') === '1',
);

$conn = false;
if ($serverName !== '' && $connectionInfo['UID'] !== '') {
    $conn = sqlsrv_connect($serverName, $connectionInfo);
}

if (!$conn && !defined('INVSTATUS_API') && !defined('ARREPORT_API') && !defined('APP_SETTINGS')) {
    echo "Connection error. Update the GP connection in <a href=\"settings.php\">settings</a>.<br />";
    die(print_r(sqlsrv_errors(), true));
}
