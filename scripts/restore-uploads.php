<?php
declare(strict_types=1);

/* Restore encrypted upload archive into an isolated directory (never overwrite live uploads). */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!function_exists('proc_open')){fwrite(STDERR,"proc_open unavailable.\n");exit(2);}
$file=$argv[1]??'';$target=$argv[2]??'';$phrase=getenv('PLUSCHAT_BACKUP_PASSPHRASE')?:'';
if($file===''||!is_file($file)||$target===''||strlen($phrase)<20||dirname($target)!==sys_get_temp_dir()||!str_starts_with(basename($target),'pluschat-restore-')||!preg_match('/^[A-Za-z0-9._-]+$/',basename($target))){fwrite(STDERR,"Usage: php scripts/restore-uploads.php BACKUP.tar.gz.gpg ISOLATED_TARGET_DIR; set passphrase env.\n");exit(2);}
if(is_dir($target)&&count(array_diff(scandir($target),['.','..']))>0){fwrite(STDERR,"Target directory must be empty.\n");exit(3);}
if(!is_dir($target)&&!mkdir($target,0700,true)&&!is_dir($target)){fwrite(STDERR,"Cannot create isolated target directory.\n");exit(3);}
$tmp=tempnam(sys_get_temp_dir(),'pc-upload-restore-');$secret=tempnam(sys_get_temp_dir(),'pc-pass-');
if($tmp===false||$secret===false){fwrite(STDERR,"Cannot create temporary files.\n");exit(3);}
@chmod($tmp,0600);@chmod($secret,0600);file_put_contents($secret,$phrase,LOCK_EX);$env=['PATH'=>(getenv('PATH')?:'/usr/bin:/bin')];
$p=proc_open(['gpg','--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$secret,'--decrypt','--output',$tmp,$file],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
if(is_resource($p)){fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);}else{$code=99;$err='gpg unavailable';}
@unlink($secret);
if($code!==0||filesize($tmp)<32){@unlink($tmp);fwrite(STDERR,"Archive decrypt/check failed.\n");exit(4);}
$p=proc_open(['tar','-xzf',$tmp,'-C',$target,'--no-same-owner','--no-same-permissions'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
if(is_resource($p)){fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);}else{$code=99;$err='tar unavailable';}
@unlink($tmp);
if($code!==0){fwrite(STDERR,"Archive extraction failed: ".substr((string)$err,0,400)."\n");exit(5);}
fwrite(STDOUT,"Upload archive restored into isolated directory: ".$target."\n");
