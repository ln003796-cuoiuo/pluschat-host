# Pluschat API feature notes

Friend requests, saved messages, and polls are implemented as separate PHP modules.


Static checks: `php tests/check-server.php` and `php tests/check-features.php`.

## Chat organization
- `GET /chat-preferences` — pin/archive/mute settings for conversations.
- `POST /conversations/{conversationId}/preferences` with `pinned`, `archived`, and optional `muted_until`.
- `GET /chat-folders` and `POST /chat-folders` with `name`.
- `DELETE /chat-folders/{folderId}`.
- `POST /chat-folders/{folderId}/items` with `conversation_id`.
- `DELETE /chat-folders/{folderId}/items/{conversationId}`.

Existing installations must apply `database/migrations/001_social_features.sql` and `database/migrations/002_chat_organization.sql` in order. Fresh installs get the same tables from `database/schema.sql`.
