<?php
declare(strict_types=1);
namespace PlusChat\Http;
final class Router{
 private array $r=[];
 public function get(string $p,callable $h):void{$this->r['GET'][$p]=$h;}
 public function post(string $p,callable $h):void{$this->r['POST'][$p]=$h;}
 public function put(string $p,callable $h):void{$this->r['PUT'][$p]=$h;}
 public function delete(string $p,callable $h):void{$this->r['DELETE'][$p]=$h;}
 public function dispatch(Request $q):void{try{$h=$this->r[$q->method][$q->path]??null;if(!$h){Response::json(['error'=>['code'=>'NOT_FOUND']],404)->send();} $v=$h($q);if($v instanceof Response)$v->send();Response::json(['error'=>['code'=>'SERVER_ERROR']],500)->send();}catch(\RuntimeException $e){$m=$e->getMessage();$status=$m==='unauthorized'?401:($m==='forbidden'?403:422);Response::json(['error'=>['code'=>strtoupper($m)]],$status)->send();}catch(\Throwable $e){error_log('request='.$q->requestId.' '.$e->getMessage());Response::json(['error'=>['code'=>'INTERNAL_ERROR','request_id'=>$q->requestId]],500)->send();}}
}
