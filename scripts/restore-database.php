<?php
declare(strict_types=1);

/* Manual CLI restore to a separate, pre-created MySQL database only.
 * Requires gpg and mysql client. Never point RESTORE_TARGET_DB at production.
 * Set PLUSCHAT_DB_HOST, PLUSCHAT_DB_USER/PASSWORD, PLUSCHAT_BACKUP_PASSPHRASE,
 * PLUSCHAT_RESTORE_TARGET_DB and pass the .sql.gpg file as argv[1].
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!function_exists('proc_open')){fwrite(STDERR,"proc_open unavailable.\n");exit(2);}
$file=$argv[1]??'';$host=getenv('PLUSCHAT_DB_HOST')?:'localhost';$user=getenv('PLUSCHAT_DB_USER')?:'';
$password=getenv('PLUSCHAT_DB_PASSWORD')?:'';$phrase=getenv('PLUSCHAT_BACKUP_PASSPHRASE')?:'';$target=getenv('PLUSCHAT_RESTORE_TARGET_DB')?:'';
$source=getenv('PLUSCHAT_DB_NAME')?:'';
if($file===''||!is_file($file)||$user===''||$password===''||strlen($phrase)<20||!preg_match('/^[A-Za-z0-9_]+$/',$target)||!preg_match('/^[A-Za-z0-9_]+$/',$source)||$target===$source){
 fwrite(STDERR,"Require backup file, credentials, passphrase and a pre-created target DB different from source.\n");exit(2);
}
$dir=sys_get_temp_dir();$sql=tempnam($dir,'pc-restore-');$secret=tempnam($dir,'pc-pass-');
if($sql===false||$secret===false){fwrite(STDERR,"Cannot create temporary restore files.\n");exit(3);}
@chmod($sql,0600);@chmod($secret,0600);file_put_contents($secret,$phrase,LOCK_EX);
$env=['PATH'=>(getenv('PATH')?:'/usr/bin:/bin')];
$p=proc_open(['gpg','--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$secret,'--decrypt','--output',$sql,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
if(is_resource($p)){fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);}else{$code=99;$err='gpg unavailable';}
@unlink($secret);
if($code!==0||filesize($sql)<64){@unlink($sql);fwrite(STDERR,"Decryption/check failed: ".substr((string)$err,0,300)."\n");exit(4);}
$rewrite=proc_open(['sed','-i','s/^USE `'.$source.'`;/USE `'.$target.'`;/',$sql],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$sp,null,['PATH'=>(getenv('PATH')?:'/usr/bin:/bin')]);
if(is_resource($rewrite)){fclose($sp[0]);$rewriteOut=stream_get_contents($sp[1]);fclose($sp[1]);$rewriteErr=stream_get_contents($sp[2]);fclose($sp[2]);$rewriteCode=proc_close($rewrite);}else{$rewriteCode=99;$rewriteErr='sed unavailable';}
if($rewriteCode!==0){@unlink($sql);fwrite(STDERR,"Cannot retarget dump to isolated DB: ".substr((string)$rewriteErr,0,300)."\\n");exit(5);}
$mysql=proc_open(['mysql','--host='.$host,'--user='.$user,'--default-character-set=utf8mb4',$target],[0=>['file',$sql,'r'],1=>['pipe','w'],2=>['pipe','w']],$mp,null,['PATH'=>(getenv('PATH')?:'/usr/bin:/bin'),'MYSQL_PWD'=>$password]);
if(is_resource($mysql)){ $out=stream_get_contents($mp[1]);fclose($mp[1]);$err=stream_get_contents($mp[2]);fclose($mp[2]);$code=proc_close($mysql);}else{$code=99;$err='mysql client unavailable';}
@unlink($sql);
if($code!==0){fwrite(STDERR,"Restore failed: ".substr((string)$err,0,500)."\n");exit(5);}
fwrite(STDOUT,"Restore imported into isolated target DB ".$target.". Now run application-level integrity checks.\n");
