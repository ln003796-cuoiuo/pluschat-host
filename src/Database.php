<?php
declare(strict_types=1);
namespace PlusChat;
use PDO;
final class Database{
 private static ?PDO $pdo=null;
 public static function pdo():PDO{
  if(self::$pdo)return self::$pdo;
  $dsn=(string)(getenv('DB_DSN')?:'pgsql:host='.(getenv('DB_HOST')?:'127.0.0.1').';port='.(getenv('DB_PORT')?:'5432').';dbname='.(getenv('DB_DATABASE')?:'pluschat'));
  self::$pdo=new PDO($dsn,getenv('DB_USERNAME')?:'',getenv('DB_PASSWORD')?:'',[
   PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
  ]);
  return self::$pdo;
 }
}
