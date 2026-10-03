<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/bootstrap.php';
use PlusChat\Auth\PasswordController;
(new PlusChat\Auth\PasswordController())->requestReset(PlusChat\Http\Request::fromGlobals())->send();
