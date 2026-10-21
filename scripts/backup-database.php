<?php
declare(strict_types=1);

/* CLI-only encrypted MySQL backup. Requires PDO MySQL, mysqldump and gpg.
 * Set PLUSCHAT_DB_HOST/NAME/USER/PASSWORD, PLUSCHAT_BACKUP_DIR and
 * PLUSCHAT_BACKUP_PASSPHRASE in the cron environment. Never put secrets in Git.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
if (!function_exists('proc_open')) { fwrite(STDERR, "proc_open unavailable; use managed hosting backups.\n"); exit(2); }
$host=getenv('PLUSCHAT_DB_HOST')?:'localhost'; $name=getenv('PLUSCHAT_DB_NAME')?:'';
$user=getenv('PLUSCHAT_DB_USER')?:''; $password=getenv('PLUSCHAT_DB_PASSWORD')?:'';
$dir=getenv('PLUSCHAT_BACKUP_DIR')?:''; $passphrase=getenv('PLUSCHAT_BACKUP_PASSPHRASE')?:'';
if ($name===''||$user===''||$password===''||$dir===''||strlen($passphrase)<20||!preg_match('/^[A-Za-z0-9_]+$/',$name)) {
 fwrite(STDERR,"Missing configuration; backup passphrase must be at least 20 characters.\n"); exit(2);
}
if (!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir)) { fwrite(STDERR,"Cannot create backup directory.\n"); exit(2); }
@chmod($dir,0700); $dir=rtrim($dir,DIRECTORY_SEPARATOR); $stamp=gmdate('Ymd-His');
$sql=$dir.'/.pluschat-'.$stamp.'.sql.tmp'; $secret=$dir.'/.pluschat-'.$stamp.'.pass.tmp';
$encrypted=$dir.'/pluschat-'.$stamp.'.sql.gpg';
try {
 $pdo=new PDO('mysql:host='.$host.';dbname='.$name.';charset=utf8mb4',$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $pdo->prepare("INSERT INTO backup_runs(run_type,status,artifact_name,started_at) VALUES('database','started',?,UTC_TIMESTAMP())")->execute([basename($encrypted)]);
 $runId=(int)$pdo->lastInsertId();
} catch(Throwable $e) { fwrite(STDERR,"Database unavailable or migration 010 not installed.\n"); exit(3); }
$writeSecret=@file_put_contents($secret,$passphrase,LOCK_EX);
if ($writeSecret===false) { fwrite(STDERR,"Cannot write temporary secret.\n"); exit(4); } @chmod($secret,0600);
$env=['PATH'=>(getenv('PATH')?:'/usr/bin:/bin'),'MYSQL_PWD'=>$password];
$dump=['mysqldump','--host='.$host,'--user='.$user,'--single-transaction','--routines','--triggers','--default-character-set=utf8mb4',$name];
$p=proc_open($dump,[0=>['pipe','r'],1=>['file',$sql,'w'],2=>['pipe','w']],$pipes,null,$env);
if(!is_resource($p)){@unlink($secret);$pdo->prepare("UPDATE backup_runs SET status='failed',finished_at=UTC_TIMESTAMP(),details='Unable to start mysqldump' WHERE id=?")->execute([$runId]);fwrite(STDERR,"mysqldump unavailable.\n");exit(5);}
fclose($pipes[0]);$err=stream_get_contents($pipes[1]);fclose($pipes[1]);$exit=proc_close($p);@chmod($sql,0600);
if($exit!==0||!is_file($sql)||filesize($sql)<64){@unlink($sql);@unlink($secret);$pdo->prepare("UPDATE backup_runs SET status='failed',finished_at=UTC_TIMESTAMP(),details=? WHERE id=?")->execute([substr(trim((string)$err)?:'mysqldump failed',0,900),$runId]);fwrite(STDERR,"Database dump failed.\n");exit(6);}
$gpg=['gpg','--batch','--yes','--pinentry-mode','loopback','--passphrase-file',$secret,'--symmetric','--cipher-algo','AES256','--output',$encrypted,$sql];
$p=proc_open($gpg,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$gp,null,$env);
if(is_resource($p)){fclose($gp[0]);$gout=stream_get_contents($gp[1]);fclose($gp[1]);$gerr=stream_get_contents($gp[2]);fclose($gp[2]);$gcode=proc_close($p);}else{$gcode=99;$gerr='Unable to start gpg';}
@unlink($sql);@unlink($secret);
if($gcode!==0||!is_file($encrypted)||filesize($encrypted)<64){@unlink($encrypted);$pdo->prepare("UPDATE backup_runs SET status='failed',finished_at=UTC_TIMESTAMP(),details=? WHERE id=?")->execute([substr(trim((string)$gerr)?:'gpg encryption failed',0,900),$runId]);fwrite(STDERR,"Backup encryption failed.\n");exit(7);}
@chmod($encrypted,0600);$sha=hash_file('sha256',$encrypted);$bytes=filesize($encrypted);
$pdo->prepare("UPDATE backup_runs SET status='success',artifact_sha256=?,bytes_written=?,finished_at=UTC_TIMESTAMP() WHERE id=?")->execute([$sha,$bytes,$runId]);
// Apply 90-day retention only after a new encrypted backup has succeeded.
foreach (glob($dir.'/pluschat-*.sql.gpg') ?: [] as $oldBackup) {
    if ($oldBackup !== $encrypted && is_file($oldBackup) && filemtime($oldBackup) < time() - (90 * 86400)) {
        @unlink($oldBackup);
    }
}
fwrite(STDOUT,"Encrypted backup success: ".basename($encrypted)." bytes=".$bytes." sha256=".$sha."\n");
