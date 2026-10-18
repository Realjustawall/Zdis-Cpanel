<?php
// Copy to config.php OUTSIDE the public document root. Never commit secrets.
return [
 'dsn' => 'mysql:host=localhost;port=3306;dbname=cpaneluser_zdis;charset=utf8mb4',
 'db_user' => 'cpaneluser_zdis', 'db_password' => '',
 'app_key' => '', // Generate: php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
 'setup_token' => '', // Optional web installer: set a random token of 32+ characters, remove after setup.
 'origin' => 'https://chat.example.com',
 'secure_cookies' => true,
 'storage' => __DIR__.'/storage',
 'ice_servers' => [['urls'=>'stun:stun.l.google.com:19302']],
 'session_days' => 14,
];
