<?php
declare(strict_types=1);
namespace PlusChat;
use PlusChat\Http\Request;use PlusChat\Http\Response;use PlusChat\Http\Router;use PlusChat\Security\Security;use PlusChat\Auth\AuthController;use PlusChat\Auth\PasswordController;use PlusChat\Profile\ProfileController;
final class App{
 private Router $router;
 public function __construct(private string $base){Security::headers();$this->router=new Router();$auth=new AuthController();$pw=new PasswordController();$profile=new ProfileController();$api=new ApiController();
  $this->router->get('/api/v1/health',fn()=>Response::json(['ok'=>true,'service'=>'pluschat','version'=>'1']));
  $this->router->post('/api/v1/auth/register',[$auth,'register']);$this->router->post('/api/v1/auth/verify-email',[$auth,'verifyEmail']);$this->router->post('/api/v1/auth/login',[$auth,'login']);$this->router->post('/api/v1/auth/password/request',[$pw,'requestReset']);$this->router->post('/api/v1/auth/password/reset',[$pw,'reset']);
  $this->router->post('/api/v1/profile/complete',[$profile,'complete']);
  $this->router->get('/api/v1/me',[$api,'me']);$this->router->post('/api/v1/auth/logout',[$api,'logout']);$this->router->post('/api/v1/auth/logout-all',[$api,'logoutAll']);
  $this->router->get('/api/v1/users/search',[$api,'users']);$this->router->get('/api/v1/chats',[$api,'chats']);$this->router->post('/api/v1/chats',[$api,'chatCreate']);$this->router->post('/api/v1/chats/members',[$api,'membersAdd']);
  $this->router->get('/api/v1/messages',[$api,'messages']);$this->router->post('/api/v1/messages',[$api,'messageSend']);$this->router->post('/api/v1/messages/edit',[$api,'messageEdit']);$this->router->post('/api/v1/messages/delete',[$api,'messageDelete']);$this->router->post('/api/v1/messages/reaction',[$api,'reaction']);
  $this->router->get('/api/v1/settings',[$api,'settings']);$this->router->post('/api/v1/settings',[$api,'settings']);$this->router->post('/api/v1/calls/token',[$api,'callToken']);
 }
 public function run():void{$this->router->dispatch(Request::fromGlobals());}
}
