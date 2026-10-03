<?php
declare(strict_types=1);
namespace PlusChat;
use PlusChat\Http\Request;use PlusChat\Http\Response;use PlusChat\Http\Router;use PlusChat\Security\Security;use PlusChat\Auth\AuthController;use PlusChat\Auth\PasswordController;use PlusChat\Profile\ProfileController;
final class App{
 private Router $router;
 public function __construct(private string $base){Security::headers();$this->router=new Router();$auth=new AuthController();$pw=new PasswordController();$profile=new ProfileController();$api=new ApiController();$features=new FeatureController();$files=new FileController();$admin=new AdminController();$topic=new TopicController();
  $this->router->get('/api/v1/health',fn()=>Response::json(['ok'=>true,'service'=>'pluschat','version'=>'1']));
  $this->router->post('/api/v1/auth/register',[$auth,'register']);$this->router->post('/api/v1/auth/verify-email',[$auth,'verifyEmail']);$this->router->post('/api/v1/auth/login',[$auth,'login']);$this->router->post('/api/v1/auth/password/request',[$pw,'requestReset']);$this->router->post('/api/v1/auth/password/reset',[$pw,'reset']);
  $this->router->post('/api/v1/profile/complete',[$profile,'complete']);
  $this->router->get('/api/v1/me',[$api,'me']);$this->router->post('/api/v1/auth/logout',[$api,'logout']);$this->router->post('/api/v1/auth/logout-all',[$api,'logoutAll']);
  $this->router->get('/api/v1/users/search',[$api,'users']);$this->router->get('/api/v1/chats',[$api,'chats']);$this->router->post('/api/v1/chats',[$api,'chatCreate']);$this->router->post('/api/v1/chats/members',[$api,'membersAdd']);
  $this->router->get('/api/v1/messages',[$api,'messages']);$this->router->post('/api/v1/messages',[$api,'messageSend']);$this->router->post('/api/v1/messages/edit',[$api,'messageEdit']);$this->router->post('/api/v1/messages/delete',[$api,'messageDelete']);$this->router->post('/api/v1/messages/reaction',[$api,'reaction']);
  $this->router->get('/api/v1/settings',[$api,'settings']);$this->router->post('/api/v1/settings',[$api,'settings']);
  $this->router->get('/api/v1/topics',[$features,'topics']);$this->router->post('/api/v1/topics',[$features,'topics']);$this->router->post('/api/v1/topics/update',[$features,'topicUpdate']);
  $this->router->get('/api/v1/contacts',[$features,'contacts']);$this->router->post('/api/v1/contacts',[$features,'contacts']);$this->router->post('/api/v1/blocks',[$features,'block']);$this->router->post('/api/v1/reports',[$features,'report']);
  $this->router->get('/api/v1/notifications',[$features,'notifications']);$this->router->post('/api/v1/notifications/read',[$features,'notifications']);$this->router->get('/api/v1/search/messages',[$features,'search']);
  $this->router->get('/api/v1/chat-settings',[$features,'chatSetting']);$this->router->post('/api/v1/chat-settings',[$features,'chatSetting']);$this->router->post('/api/v1/polls',[$features,'pollCreate']);$this->router->post('/api/v1/polls/vote',[$features,'pollVote']);$this->router->post('/api/v1/files',[$files,'upload']);$this->router->post('/api/v1/topics/convert',[$topic,'convert']);
  $this->router->get('/admin/api/dashboard',[$admin,'dashboard']);$this->router->get('/admin/api/users',[$admin,'users']);$this->router->get('/admin/api/errors',[$admin,'errors']);$this->router->get('/admin/api/security',[$admin,'security']);$this->router->get('/admin/api/reports',[$admin,'reports']);$this->router->post('/admin/api/users/block',[$admin,'blockUser']);
 }
 public function run():void{$this->router->dispatch(Request::fromGlobals());}
}
