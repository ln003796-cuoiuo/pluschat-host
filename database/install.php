<?php
declare(strict_types=1);

require dirname(__DIR__).'/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
$token=(string)($_GET['token']??'');
$expected=(string)(getenv('INSTALL_TOKEN')?:'');
if($expected===''||!hash_equals($expected,$token)){http_response_code(403);echo 'Installer access denied';exit;}
$host=getenv('DB_HOST')?:'127.0.0.1';
$port=getenv('DB_PORT')?:'5432';
$name=getenv('DB_DATABASE')?:'pluschat';
$user=getenv('DB_ADMIN_USERNAME')?:getenv('DB_USERNAME')?:'';
$pass=getenv('DB_ADMIN_PASSWORD')?:getenv('DB_PASSWORD')?:'';
try{
 $pdo=new PDO("pgsql:host={$host};port={$port};dbname=postgres",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $safe=preg_replace('/[^a-zA-Z0-9_]/','',$name);
 $exists=$pdo->prepare('SELECT 1 FROM pg_database WHERE datname=?');$exists->execute([$safe]);
 if(!$exists->fetchColumn()){$pdo->exec('CREATE DATABASE "'.$safe.'"');}
 $db=new PDO("pgsql:host={$host};port={$port};dbname={$safe}",$user,$pass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
 $db->exec('CREATE EXTENSION IF NOT EXISTS pgcrypto');
 $tables=[
 'users'=>'CREATE TABLE IF NOT EXISTS users (id BIGSERIAL PRIMARY KEY,email VARCHAR(320) UNIQUE NOT NULL,password_hash TEXT NOT NULL,display_name VARCHAR(120) NOT NULL,username VARCHAR(32) UNIQUE,first_name VARCHAR(120),last_name VARCHAR(120),birth_date DATE,avatar_file_id BIGINT,email_verified BOOLEAN NOT NULL DEFAULT FALSE,is_blocked BOOLEAN NOT NULL DEFAULT FALSE,location JSONB,school_or_work JSONB,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'sessions'=>'CREATE TABLE IF NOT EXISTS sessions (id BIGSERIAL PRIMARY KEY,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,token_hash BYTEA UNIQUE NOT NULL,expires_at TIMESTAMPTZ NOT NULL,revoked_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'email_verifications'=>'CREATE TABLE IF NOT EXISTS email_verifications (id BIGSERIAL PRIMARY KEY,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,code_hash TEXT NOT NULL,expires_at TIMESTAMPTZ NOT NULL,used_at TIMESTAMPTZ)',
 'password_resets'=>'CREATE TABLE IF NOT EXISTS password_resets (id BIGSERIAL PRIMARY KEY,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,token_hash BYTEA UNIQUE NOT NULL,expires_at TIMESTAMPTZ NOT NULL,used_at TIMESTAMPTZ)',
 'chats'=>'CREATE TABLE IF NOT EXISTS chats (id BIGSERIAL PRIMARY KEY,type VARCHAR(20) NOT NULL DEFAULT 'private',name VARCHAR(160),description TEXT,owner_id BIGINT REFERENCES users(id),created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'chat_members'=>'CREATE TABLE IF NOT EXISTS chat_members (chat_id BIGINT NOT NULL REFERENCES chats(id) ON DELETE CASCADE,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,role VARCHAR(30) NOT NULL DEFAULT 'member',joined_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),PRIMARY KEY(chat_id,user_id))',
 'messages'=>'CREATE TABLE IF NOT EXISTS messages (id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL REFERENCES chats(id) ON DELETE CASCADE,sender_id BIGINT NOT NULL REFERENCES users(id),body TEXT,reply_to_id BIGINT REFERENCES messages(id),topic_id BIGINT,edited_at TIMESTAMPTZ,deleted_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'files'=>'CREATE TABLE IF NOT EXISTS files (id VARCHAR(64) PRIMARY KEY,owner_id BIGINT NOT NULL REFERENCES users(id),original_name TEXT NOT NULL,mime_type VARCHAR(160) NOT NULL,size_bytes BIGINT NOT NULL,sha256 BYTEA NOT NULL,storage_path TEXT NOT NULL,encrypted BOOLEAN NOT NULL DEFAULT TRUE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'contacts'=>'CREATE TABLE IF NOT EXISTS contacts (owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,contact_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,alias VARCHAR(120),created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),PRIMARY KEY(owner_id,contact_id))',
 'blocks'=>'CREATE TABLE IF NOT EXISTS blocks (owner_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,blocked_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),PRIMARY KEY(owner_id,blocked_id))',
 'reports'=>'CREATE TABLE IF NOT EXISTS reports (id BIGSERIAL PRIMARY KEY,reporter_id BIGINT NOT NULL REFERENCES users(id),target_user_id BIGINT REFERENCES users(id),chat_id BIGINT REFERENCES chats(id),message_id BIGINT,reason VARCHAR(80) NOT NULL,details TEXT,status VARCHAR(20) NOT NULL DEFAULT 'open',created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'notifications'=>'CREATE TABLE IF NOT EXISTS notifications (id BIGSERIAL PRIMARY KEY,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,type VARCHAR(50) NOT NULL,payload JSONB NOT NULL DEFAULT '{}',read_at TIMESTAMPTZ,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'topics'=>'CREATE TABLE IF NOT EXISTS topics (id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL REFERENCES chats(id) ON DELETE CASCADE,name VARCHAR(120) NOT NULL,icon VARCHAR(20),archived BOOLEAN NOT NULL DEFAULT FALSE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'chat_settings'=>'CREATE TABLE IF NOT EXISTS chat_settings (chat_id BIGINT NOT NULL REFERENCES chats(id) ON DELETE CASCADE,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,muted BOOLEAN NOT NULL DEFAULT FALSE,archived BOOLEAN NOT NULL DEFAULT FALSE,disappearing_seconds INTEGER,topic_notifications BOOLEAN NOT NULL DEFAULT TRUE,PRIMARY KEY(chat_id,user_id))',
 'polls'=>'CREATE TABLE IF NOT EXISTS polls (id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL REFERENCES chats(id) ON DELETE CASCADE,creator_id BIGINT NOT NULL REFERENCES users(id),question TEXT NOT NULL,options JSONB NOT NULL,anonymous BOOLEAN NOT NULL DEFAULT FALSE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'poll_votes'=>'CREATE TABLE IF NOT EXISTS poll_votes (poll_id BIGINT NOT NULL REFERENCES polls(id) ON DELETE CASCADE,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,option_index INTEGER NOT NULL,PRIMARY KEY(poll_id,user_id))',
 'reactions'=>'CREATE TABLE IF NOT EXISTS reactions (message_id BIGINT NOT NULL REFERENCES messages(id) ON DELETE CASCADE,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,reaction VARCHAR(64) NOT NULL,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),PRIMARY KEY(message_id,user_id,reaction))',
 'app_errors'=>'CREATE TABLE IF NOT EXISTS app_errors (id BIGSERIAL PRIMARY KEY,request_id VARCHAR(64),message TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())',
 'security_events'=>'CREATE TABLE IF NOT EXISTS security_events (id BIGSERIAL PRIMARY KEY,user_id BIGINT REFERENCES users(id),event_type VARCHAR(80) NOT NULL,ip INET,metadata JSONB,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())'
 ];
 foreach($tables as $sql)$db->exec($sql);
 echo '<h1>PlusChat database installed</h1><p>Database: '.htmlspecialchars($safe,ENT_QUOTES,'UTF-8').'</p><p>Tables: '.count($tables).'</p>';
}catch(Throwable $e){http_response_code(500);echo 'Installation failed';}
