<?php
declare(strict_types=1);
namespace PlusChat\Calls;
use PDO;
final class Db{private static ?PDO $p=null;public static function p():PDO{if(self::$p)return self::$p;$dsn=(string)(getenv('CALL_DB_DSN')?:$_ENV['CALL_DB_DSN']??'');self::$p=new PDO($dsn,(string)(getenv('CALL_DB_USERNAME')?:$_ENV['CALL_DB_USERNAME']??''),(string)(getenv('CALL_DB_PASSWORD')?:$_ENV['CALL_DB_PASSWORD']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);return self::$p;}}
