<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap.php';
$token=(string)($_GET['token']??'');$expected=(string)(getenv('INSTALL_TOKEN')??'');
if($expected===''||!hash_equals($expected,$token)){http_response_code(403);exit('Access denied');}
try{
 $db=PlusChat\Database::pdo();
 $sql=[
"CREATE TABLE IF NOT EXISTS chat_settings(chat_id BIGINT NOT NULL REFERENCES chats(id) ON DELETE CASCADE,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,muted BOOLEAN NOT NULL DEFAULT FALSE,archived BOOLEAN NOT NULL DEFAULT FALSE,disappearing_seconds INTEGER,topic_notifications BOOLEAN NOT NULL DEFAULT TRUE,PRIMARY KEY(chat_id,user_id))",
"CREATE TABLE IF NOT EXISTS polls(id BIGSERIAL PRIMARY KEY,chat_id BIGINT NOT NULL REFERENCES chats(id) ON DELETE CASCADE,creator_id BIGINT NOT NULL REFERENCES users(id),question TEXT NOT NULL,options JSONB NOT NULL,anonymous BOOLEAN NOT NULL DEFAULT FALSE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())",
"CREATE TABLE IF NOT EXISTS poll_votes(poll_id BIGINT NOT NULL REFERENCES polls(id) ON DELETE CASCADE,user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,option_index INTEGER NOT NULL,PRIMARY KEY(poll_id,user_id))",
"CREATE TABLE IF NOT EXISTS files(id VARCHAR(64) PRIMARY KEY,owner_id BIGINT NOT NULL REFERENCES users(id),original_name TEXT NOT NULL,mime_type VARCHAR(160) NOT NULL,size_bytes BIGINT NOT NULL,sha256 BYTEA NOT NULL,storage_path TEXT NOT NULL,encrypted BOOLEAN NOT NULL DEFAULT TRUE,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())",
"CREATE TABLE IF NOT EXISTS user_settings(user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,settings JSONB NOT NULL DEFAULT '{}'::jsonb,updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW())",
"CREATE TABLE IF NOT EXISTS security_events(id BIGSERIAL PRIMARY KEY,user_id BIGINT REFERENCES users(id),event_type VARCHAR(80) NOT NULL,ip INET,metadata JSONB,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())",
"CREATE TABLE IF NOT EXISTS app_errors(id BIGSERIAL PRIMARY KEY,request_id VARCHAR(80),message TEXT,created_at TIMESTAMPTZ NOT NULL DEFAULT NOW())",
"CREATE TABLE IF NOT EXISTS community_groups(chat_id BIGINT PRIMARY KEY REFERENCES chats(id) ON DELETE CASCADE,kind VARCHAR(20) NOT NULL,key_name VARCHAR(160) UNIQUE NOT NULL)",
"CREATE TABLE IF NOT EXISTS location_communities(chat_id BIGINT PRIMARY KEY REFERENCES chats(id) ON DELETE CASCADE,lat_min DOUBLE PRECISION,lat_max DOUBLE PRECISION,lon_min DOUBLE PRECISION,lon_max DOUBLE PRECISION)",
"CREATE INDEX IF NOT EXISTS idx_messages_chat ON messages(chat_id,id DESC)",
"CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id,expires_at)",
"CREATE INDEX IF NOT EXISTS idx_notifications_user ON notifications(user_id,created_at DESC)"
 ];
 foreach($sql as $s)$db->exec($s);
 echo 'PlusChat schema upgrade OK';
}catch(Throwable $e){http_response_code(500);echo 'Upgrade failed';}
