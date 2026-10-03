<?php
declare(strict_types=1);
namespace PlusChat\Auth;
use PHPMailer\PHPMailer\PHPMailer;
final class Mailer {
  public function verification(string $to,string $name,string $code):void{
    $m=new PHPMailer(true);$m->isSMTP();$m->Host=getenv('MAIL_HOST')?:'';$m->Port=(int)(getenv('MAIL_PORT')?:587);$m->SMTPAuth=true;$m->Username=getenv('MAIL_USERNAME')?:'';$m->Password=getenv('MAIL_PASSWORD')?:'';
    $enc=getenv('MAIL_ENCRYPTION')?:'tls';if($enc==='tls')$m->SMTPSecure=PHPMailer::ENCRYPTION_STARTTLS;elseif($enc==='ssl')$m->SMTPSecure=PHPMailer::ENCRYPTION_SMTPS;
    $m->setFrom(getenv('MAIL_FROM')?:$m->Username,getenv('MAIL_FROM_NAME')?:'PlusChat');$m->addAddress($to,$name);$m->isHTML(true);$m->Subject='Подтверждение регистрации PlusChat';
    $safe=htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');$m->Body='<h2>PlusChat</h2><p>Здравствуйте, '.$safe.'!</p><p>Код подтверждения:</p><p style="font-size:28px"><b>'.$code.'</b></p>'; $m->AltBody='Код подтверждения PlusChat: '.$code;$m->send();
  }
}
