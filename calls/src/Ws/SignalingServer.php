<?php
declare(strict_types=1);
namespace PlusChat\Calls\Ws;
use Ratchet\MessageComponentInterface; use Ratchet\ConnectionInterface; use SplObjectStorage; use PlusChat\Calls\Token;
final class SignalingServer implements MessageComponentInterface{
 private SplObjectStorage $clients; private array $rooms=[];
 public function __construct(){$this->clients=new SplObjectStorage();}
 public function onOpen(ConnectionInterface $c):void{$q=$c->httpRequest->getUri()->getQuery();parse_str($q,$v);$p=Token::verify((string)($v['token']??''));if(!$p){$c->close();return;}$c->uid=(int)$p['uid'];$c->call=null;$this->clients->attach($c);}
 public function onMessage(ConnectionInterface $from,$msg):void{$d=json_decode((string)$msg,true);if(!is_array($d)||!isset($d['type']))return;$type=(string)$d['type'];if($type==='join'){ $call=(string)($d['call_id']??'');if(!preg_match('/^[a-f0-9]{32}$/',$call))return;$from->call=$call;$this->rooms[$call]??=[];$this->rooms[$call][$from->resourceId]=$from;$this->broadcast($call,$from,['type'=>'peer_joined','peer_id'=>$from->resourceId]);return;}if(!$from->call)return;if(in_array($type,['offer','answer','ice','mute','camera','screen','hangup'],true))$this->broadcast($from->call,$from,['type'=>$type,'peer_id'=>$from->resourceId,'data'=>$d['data']??null]);}
 private function broadcast(string $room,ConnectionInterface $except,array $data):void{foreach($this->rooms[$room]??[] as $c)if($c!==$except)$c->send(json_encode($data,JSON_UNESCAPED_SLASHES));}
 public function onClose(ConnectionInterface $c):void{$this->clients->detach($c);if($c->call){unset($this->rooms[$c->call][$c->resourceId]);$this->broadcast($c->call,$c,['type'=>'peer_left','peer_id'=>$c->resourceId]);}}
 public function onError(ConnectionInterface $c,\Exception $e):void{$c->close();}
}
