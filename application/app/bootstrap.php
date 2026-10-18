<?php
declare(strict_types=1);
foreach(['core','permissions','advanced','community','bots','integrations','auth','groups','messages','files','social','admin','realtime','extra'] as $module) require_once __DIR__.'/'.$module.'.php';
