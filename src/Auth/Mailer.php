<?php
declare(strict_types=1);
namespace PlusChat\Auth;

final class Mailer {
 public function verification(string $to,string $name,string $code):void {
  $host=getenv('MAIL_HOST')?:'';$port=(int)(getenv('MAIL_PORT')?:587);$user=getenv('MAIL_USERNAME')?:'';$pass=getenv('MAIL_PASSWORD')?:'';
  if($host===''||$user===''||$pass==='')throw new \RuntimeException('Mail is not configured');
  $from=getenv('MAIL_FROM')?:$user;$fromName=getenv('MAIL_FROM_NAME')?:'PlusChat';
  $transport=(getenv('MAIL_ENCRYPTION')?:'tls')==='ssl'?'ssl://':'';
  $fp=stream_socket_client($transport.$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT);
  if(!$fp)throw new \RuntimeException('Mail connection failed');
  stream_set_timeout($fp,15);$this->expect($fp,220);$this->cmd($fp,'EHLO pluschat',250);
  if($transport===''){$this->cmd($fp,'STARTTLS',220);if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new \RuntimeException('TLS failed');$this->cmd($fp,'EHLO pluschat',250);}
  $this->cmd($fp,'AUTH LOGIN',334);$this->cmd($fp,base64_encode($user),334);$this->cmd($fp,base64_encode($pass),235);
  $this->cmd($fp,'MAIL FROM:<'.$from.'>',250);$this->cmd($fp,'RCPT TO:<'.$to.'>',250);$this->cmd($fp,'DATA',354);
  $safeName=htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$subject='Подтверждение регистрации PlusChat';
  $body='<html><body><h2>PlusChat</h2><p>Здравствуйте, '.$safeName.'!</p><p>Код подтверждения:</p><p style="font-size:28px"><b>'.$code.'</b></p></body></html>';
  $headers='From: '.$this->header($fromName).' <'.$from.'>\r\nTo: <'.$to.">\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\nSubject: =?UTF-8?B?".base64_encode($subject)."?=\r\n";
  $this->cmd($fp,$headers."\r\n".$body."\r\n.",250);$this->cmd($fp,'QUIT',221);fclose($fp);
 }
 private function header(string $v):string{return preg_replace('/[\r\n]+/',' ',trim($v))??'';}
 private function cmd($fp,string $cmd,int $expected):void{fwrite($fp,$cmd."\r\n");$this->expect($fp,$expected);}
 private function expect($fp,int $expected):void{$line='';while(($part=fgets($fp,515))!==false){$line=$part;if(strlen($part)<4||$part[3]===' ')break;}if((int)substr($line,0,3)!==$expected)throw new \RuntimeException('SMTP error');}
}
