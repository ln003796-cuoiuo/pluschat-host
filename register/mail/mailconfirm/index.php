<?php
declare(strict_types=1);
require dirname(__DIR__,3).'/bootstrap.php';
use PlusChat\Auth\AuthController;
(new PlusChat\Auth\AuthController())->verifyEmail(PlusChat\Http\Request::fromGlobals())->send();
