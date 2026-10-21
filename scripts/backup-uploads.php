<?php
declare(strict_types=1);

/* CLI-only encrypted upload backup. Requires tar and gpg.
 * Uses the same PLUSCHAT_BACKUP_DIR and PLUSCHAT_BACKUP_PASSPHRASE as DB backup.
 */
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
if(!function_exists('proc_open')){fwrite(STDERR,"proc_open unavailable.\n");exit(2);}
$dir=getenv('PLUSCHAT_BACKUP_DIR')?:'';$phrase=getenv('PLUSCHAT_BACKUP_PASSPHRASE')?:'';
$uploads=dirname(__DIR__).'/storage/uploads';
if($dir===''||strlen($phrase)<20||!is_dir($uploads)){fwrite(STDERR,"Missing backup configuration or upload directory.\n");exit(2);}
if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)){fwrite(STDERR,"Cannot create backup directory.\n");exit(2);}
@chmod($dir,0700);$dir=rtrim($dir,DIRECTORY_SEPARATOR);$stamp=gmdate('Ymd-His');
$tar=$dir.'/.pluschat-uploads-'.$stamp.'.tar.gz.tmp';$secret=$dir.'/.pluschat-uploads-'.$stamp.'.pass.tmp';$final=$dir.'/pluschat-uploads-'.$stamp.'.tar.gz.gpg';
try{$pdo=new PDO('mysql:host='.(getenv('PLUSCHAT_DB_HOST')?:'localhost').';dbname='.(getenv('PLUSCHAT_DB_NAME')?:'').';charset=utf8mb4',getenv('PLUSCHAT_DB_USER')?:'',getenv('PLUSCHAT_DB_PASSWORD')?:'', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$pdo->prepare("INSERT INTO backup_runs(run_type,status,artifact_name,started_at) VALUES('uploads','started',?,UTC_TIMESTAMP())")->execute([basename($final)]);$runId=(int)$pdo->lastInsertId();}catch(Throwable $e){fwrite(STDERR,"Database unavailable or backup_runs table missing.\n");exit(3);}
file_put_contents($secret,$phrase,LOCK_EX);@chmod($secret,0600);$env=['PATH'=>(getenv('PATH')?:'/usr/bin:/bin')];
$p=proc_open(['tar','-czf',$tar,'-C',$uploads,'.'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,$env);
if(is_resource($p)){fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[2]);$code=proc_close($p);}else{$code=99;$err='tar unavailable';}
@chmod($tar,0600);
if($code!==0||!is_file($tar)||filesize($tar)<32){@unlink($tar);@unlink($secret);$pdo->prepare("UPDATE backup_runs SET status='failed',finished_at=UTC_TIMESTAMP(),details=? WHERE id=?")->execute([substr((string)$err,0,900),$runId]);fwrite(STDERR,"Upload archive failed.\n");exit(4);}
$p=proc_open(['gpg','--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$secret,'--symmetric','--cipher-algo','AES256','--output',$final,$tar],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$gp,null,$env);
if(is_resource($p)){fclose($gp[0]);$out=stream_get_contents($gp[1]);fclose($gp[1]);$err=stream_get_contents($gp[2]);fclose($gp[2]);$code=proc_close($p);}else{$code=99;$err='gpg unavailable';}
@unlink($tar);@unlink($secret);
if($code!==0||!is_file($final)||filesize($final)<32){@unlink($final);$pdo->prepare("UPDATE backup_runs SET status='failed',finished_at=UTC_TIMESTAMP(),details=? WHERE id=?")->execute([substr((string)$err,0,900),$runId]);fwrite(STDERR,"Upload backup encryption failed.\n");exit(5);}
@chmod($final,0600);$sha=hash_file('sha256',$final);$bytes=filesize($final);
$pdo->prepare("UPDATE backup_runs SET status='success',artifact_sha256=?,bytes_written=?,finished_at=UTC_TIMESTAMP() WHERE id=?")->execute([$sha,$bytes,$runId]);
foreach(glob($dir.'/pluschat-uploads-*.tar.gz.gpg')?:[] as $old){if($old!==$final&&is_file($old)&&filemtime($old)<time()-90*86400)@unlink($old);}
fwrite(STDOUT,"Encrypted uploads backup success: ".basename($final)." bytes=".$bytes." sha256=".$sha."\n");
