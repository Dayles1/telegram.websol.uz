<?php
require 'vendor/autoload.php';

use danog\MadelineProto\API;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\AppInfo;

$sessionFile = __DIR__ . '/sessions/my_user.madeline';

$appInfo = new AppInfo([
    'api_id' => (int) getenv('TELEGRAM_API_ID'),
    'api_hash' => getenv('TELEGRAM_API_HASH'),
]);

$settings = new Settings();
$settings->setAppInfo($appInfo);
$MadelineProto = new API($sessionFile, $settings);

$MadelineProto->phoneLogin('+998950672506');  
;
