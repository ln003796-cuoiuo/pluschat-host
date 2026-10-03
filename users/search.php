<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use PlusChat\ApiController;
(new PlusChat\ApiController())->users(PlusChat\Http\Request::fromGlobals())->send();
