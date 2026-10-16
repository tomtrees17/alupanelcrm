<?php
// Copy to config.local.php on the server and fill in. config.local.php is
// gitignored: it never reaches GitHub and the nightly `git reset --hard`
// deploy leaves it alone. Only keys you set here override config.php.
return [
    'offsite' => [
        'driver'     => 'oss',
        'key_id'     => '',     // RAM user AccessKey ID (PutObject-only policy)
        'key_secret' => '',     // RAM user AccessKey Secret
    ],
    // 'wa' => ['driver' => 'fonnte', 'token' => '', 'test_to' => ''],
    // Anthropic key: industry classification (客户行业) uses it as soon as it is set;
    // the inventory assistant additionally needs 'driver' => 'claude'.
    // 'ai' => ['driver' => 'claude', 'key' => ''],
    // Google Geocoding API key (客户区域 + 送货点地图).
    // 'geo' => ['driver' => 'google', 'key' => ''],
];
