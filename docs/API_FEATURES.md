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

## Stories
- `GET /stories` — feed visible to the signed-in user.
- `POST /stories` with an encrypted `payload`, `visibility` (`friends` or `everyone`), and optional `expires_in_hours` from 1 to 48.
- `POST /stories/{storyId}/view` — record a view when allowed by audience and block settings.
- `DELETE /stories/{storyId}` — delete your own story.

The story payload must use the `pc-e2ee-v1` envelope. This stores opaque ciphertext; it does not implement or prove the Android-side key exchange. Existing databases must also apply `database/migrations/003_stories.sql`.
