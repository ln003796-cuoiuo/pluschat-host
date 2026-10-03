<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
use PlusChat\ApiController;
(new PlusChat\ApiController())->membersAdd(PlusChat\Http\Request::fromGlobals())->send();
