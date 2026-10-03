<?php
declare(strict_types=1);
$root=__DIR__;
spl_autoload_register(static function(string $class)use($root):void{
 $prefix='PlusChat\\';if(!str_starts_with($class,$prefix))return;
 $file=$root.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';if(is_file($file))require_once $file;
});
if(is_file($root.'/.env'))foreach(file($root.'/.env',FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
 $line=trim($line);if($line===''||$line[0]==='#')continue;[$k,$v]=array_pad(explode('=',$line,2),2,'');$k=trim($k);$v=trim($v);
 if($k!==''&&getenv($k)===false)putenv($k.'='.trim($v," \"'"));
}
