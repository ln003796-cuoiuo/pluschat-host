<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use PlusChat\ApiController;
(new PlusChat\ApiController())->chats(PlusChat\Http\Request::fromGlobals())->send();
