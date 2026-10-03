#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Ratchet\Server\IoServer;use Ratchet\Http\HttpServer;use Ratchet\WebSocket\WsServer;use React\Socket\SocketServer;use React\EventLoop\Loop;use PlusChat\Calls\Ws\SignalingServer;
$port=(int)(getenv('CALL_WS_PORT')?:8080);$socket=new SocketServer('0.0.0.0:'.$port,[],Loop::get());$server=new IoServer(new HttpServer(new WsServer(new SignalingServer())),$socket,Loop::get());Loop::get()->run();
