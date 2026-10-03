<?php
declare(strict_types=1);
namespace PlusChat\Http;
final class Router {
  private array $r=[];
  public function get(string $p,callable $h):void{$this->r['GET'][$p]=$h;}
  public function post(string $p,callable $h):void{$this->r['POST'][$p]=$h;}
  public function dispatch(Request $q):void{
    try{$h=$this->r[$q->method][$q->path]??null;if(!$h)Response::json(['error'=>['code'=>'NOT_FOUND']],404)->send();$v=$h($q);($v instanceof Response?$v:Response::json(['error'=>['code'=>'SERVER_ERROR']],500))->send();}
    catch(\Throwable $e){error_log('request='.$q->requestId.' '.(string)$e);Response::json(['error'=>['code'=>'INTERNAL_ERROR','message'=>'Internal server error','request_id'=>$q->requestId]],500)->send();}
  }
}
