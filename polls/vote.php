<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
use PlusChat\FeatureController;
(new PlusChat\FeatureController())->pollVote(PlusChat\Http\Request::fromGlobals())->send();
