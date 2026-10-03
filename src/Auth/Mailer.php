<?php
declare(strict_types=1);
namespace PlusChat\Auth;
final class Mailer{
 public function verification(string $to,string $name,string $code):void{$this->send($to,'PlusChat email verification','<h2>PlusChat</h2><p>Hello, '.htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'!</p><p>Your verification code:</p><p style="font-size:28px"><b>'.htmlspecialchars($code,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</b></p>','Your verification code: '.$code);}
 public function send(string $to,string $subject,string $html,string $text=''):void{
  if(!filter_var($to,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('invalid_recipient');
  $host=getenv('MAIL_HOST')?:'';$port=(int)(getenv('MAIL_PORT')?:587);$user=getenv('MAIL_USERNAME')?:'';$pass=getenv('MAIL_PASSWORD')?:'';if($host===''||$user===''||$pass==='')throw new \RuntimeException('mail_not_configured');
  $from=getenv('MAIL_FROM')?:$user;$name=preg_replace('/[\r\n]+/',' ',trim((string)(getenv('MAIL_FROM_NAME')?:'PlusChat')));$enc=getenv('MAIL_ENCRYPTION')?:'tls';$target=$enc==='ssl'?'ssl://'.$host:$host;
  $fp=stream_socket_client($target.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT);if(!$fp)throw new \RuntimeException('mail_connection');stream_set_timeout($fp,15);
  $this->expect($fp,220);$this->cmd($fp,'EHLO pluschat',250);
  if($enc==='tls'){$this->cmd($fp,'STARTTLS',220);if(!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new \RuntimeException('mail_tls');$this->cmd($fp,'EHLO pluschat',250);}
  $this->cmd($fp,'AUTH LOGIN',334);$this->cmd($fp,base64_encode($user),334);$this->cmd($fp,base64_encode($pass),235);$this->cmd($fp,'MAIL FROM:<'.$from.'>',250);$this->cmd($fp,'RCPT TO:<'.$to.'>',250);$this->cmd($fp,'DATA',354);
  $subject='=?UTF-8?B?'.base64_encode($subject).'?=';$headers="From: ".$name." <".$from.">\r\nTo: <".$to.">\r\nMIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nSubject: ".$subject."\r\n";
  $body=$headers."\r\n".$html."\r\n.\r\n";$this->write($fp,$body,250);$this->cmd($fp,'QUIT',221);fclose($fp);
 }
 private function cmd($fp,string $cmd,int $code):void{$this->write($fp,$cmd."\r\n",$code);}
 private function write($fp,string $data,int $code):void{fwrite($fp,$data);$this->expect($fp,$code);}
 private function expect($fp,int $code):void{$line='';while(($x=fgets($fp,515))!==false){$line=$x;if(strlen($x)<4||$x[3]===' ')break;}if((int)substr($line,0,3)!==$code)throw new \RuntimeException('smtp_error');}
}
