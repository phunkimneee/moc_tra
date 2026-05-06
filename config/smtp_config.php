<?php
/*
 * SMTP config — reads credentials from .env in project root.
 * Never hardcode credentials here; edit .env instead.
 */

$_envFile = dirname(__DIR__) . '/.env';
if (is_file($_envFile)) {
    foreach (file($_envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $_line) {
        $_line = trim($_line);
        if ($_line === '' || str_starts_with($_line, '#')) continue;
        if (!str_contains($_line, '=')) continue;
        [$_k, $_v] = explode('=', $_line, 2);
        $_k = trim($_k);
        $_v = trim($_v);
        if (!array_key_exists($_k, $_SERVER) && !array_key_exists($_k, $_ENV)) {
            putenv("{$_k}={$_v}");
            $_ENV[$_k] = $_v;
        }
    }
}
unset($_envFile, $_line, $_k, $_v);

define('SMTP_HOST',      getenv('SMTP_HOST')      ?: 'smtp.getresponse.com');
define('SMTP_PORT',      (int)(getenv('SMTP_PORT') ?: 587));
define('SMTP_USER',      getenv('SMTP_USER')      ?: '');
define('SMTP_PASS',      getenv('SMTP_PASS')      ?: '');
define('SMTP_FROM',      getenv('SMTP_FROM')      ?: getenv('SMTP_USER') ?: '');
define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Mộc Trà');
define('BREVO_API_KEY',  getenv('BREVO_API_KEY')  ?: '');
