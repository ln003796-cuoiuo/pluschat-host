<?php
declare(strict_types=1);
namespace PlusChat;
use PlusChat\Http\Request;
use PlusChat\Http\Response;
use PlusChat\Http\Router;
use PlusChat\Security\Security;
use PlusChat\Auth\AuthController;
final class App {
  private Router $router;
  public function __construct(private string $base){
    Security::headers(); $this->router=new Router(); $auth=new AuthController();
    $this->router->get('/api/v1/health',fn()=>Response::json(['ok'=>true,'service'=>'pluschat']));
    $this->router->post('/api/v1/auth/register',[$auth,'register']);
    $this->router->post('/api/v1/auth/verify-email',[$auth,'verifyEmail']);
    $this->router->post('/api/v1/auth/login',[$auth,'login']);
  }
  public function run():void{$this->router->dispatch(Request::fromGlobals());}
}
