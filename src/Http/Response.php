<?php
declare(strict_types=1);
namespace PlusChat\Http;
final class Response {
  public function __construct(private int $status,private array $data){}
  public static function json(array $data,int $status=200):self{return new self($status,$data);}
  public function send():never{http_response_code($this->status);header('Content-Type: application/json; charset=utf-8');echo json_encode($this->data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
}
