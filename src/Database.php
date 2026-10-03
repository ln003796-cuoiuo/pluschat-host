<?php
declare(strict_types=1);
namespace PlusChat;
use PDO;
final class Database {
  private static ?PDO $pdo=null;
  public static function pdo():PDO{
    if(self::$pdo)return self::$pdo;
    $dsn=getenv('DB_DSN')?:'';
    if(!$dsn)throw new \RuntimeException('DB_DSN is not configured');
    return self::$pdo=new PDO($dsn,getenv('DB_USERNAME')?:'',getenv('DB_PASSWORD')?:'',[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
    ]);
  }
}
