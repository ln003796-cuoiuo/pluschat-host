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

## Password recovery
- `POST /auth/password/forgot` with an email address. The response does not reveal whether the account exists.
- The email contains a one-hour recovery link to `/password-reset.php`.
- `POST /auth/password/reset` with token and new password is also available to API clients.
- A successful reset revokes all existing access tokens for that account.

Existing installations must apply `database/migrations/004_password_recovery.sql` after the earlier migrations.

## Message actions
- `POST /messages/{messageId}/edit` accepts a new encrypted message envelope. The author may edit for 48 hours.
- `POST /messages/{messageId}/delete` accepts `for_everyone` as a boolean. Delete-for-everyone is author-only; delete-for-me hides the message only for the current user.
- Apply `database/migrations/005_message_hiding.sql` on existing installations.
