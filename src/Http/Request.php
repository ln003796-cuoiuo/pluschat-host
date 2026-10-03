<?php
declare(strict_types=1);
namespace PlusChat\Http;
final class Request {
  public function __construct(public string $method,public string $path,public array $headers,public string $body,public string $requestId){}
  public static function fromGlobals():self{
    $h=function_exists('getallheaders')?(getallheaders()?:[]):[];
    $id=preg_replace('/[^A-Za-z0-9._-]/','',(string)($h['X-Request-ID']??''))?:bin2hex(random_bytes(16));
    return new self(strtoupper($_SERVER['REQUEST_METHOD']??'GET'),parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH)?:'/',array_change_key_case($h,CASE_LOWER),file_get_contents('php://input')?:'',substr($id,0,80));
  }
  public function json():array{$d=json_decode($this->body,true);return is_array($d)?$d:[];}
}
