## Registration and email verification
- `POST /api/v1/auth/register` requires `email`, `username`, `password`, and a non-empty `first_name`; `last_name` and `birth_date` are optional.
- A six-digit one-time code is sent to the registered email. Codes expire after 15 minutes and are stored only as a SHA-256 hash bound to the normalized email address.
- `POST /api/v1/auth/verify` accepts `email` and `code`. Login remains blocked until email verification succeeds.
- `POST /api/v1/auth/resend-verification` sends a replacement code for an unverified account. Responses intentionally do not reveal whether the email belongs to an account.
- Email delivery uses PHP `mail()`; the hosting provider must have outbound mail configured and the sender domain should have valid SPF/DKIM/DMARC records. A successful CI run does not verify real-world email delivery.

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

## Attachments and updates
- `POST /messages/{messageId}/attachments` accepts client-encrypted bytes only (`encrypted=1`, `encryption_version=pc-e2ee-v1`); the server stores the opaque bytes and never decrypts them.
- `GET /attachments/{attachmentId}` downloads an attachment only for a conversation member. Attachments hidden by a delete-for-me action are no longer downloadable by that user.
- `POST /attachments/{attachmentId}/delete` lets the message author delete the attachment.
- Direct one-to-one message sends are rejected if either participant has blocked the other.

## Applying migrations to an existing installation
Run `php database/migrate.php` using PHP CLI from the project root after `config.php` exists. The runner refuses web requests and records completed migration filenames in `schema_migrations`. Take a database backup before applying updates. A fresh installation continues to use `database/schema.sql` through the setup page.

## Message reactions
- `GET /messages/{messageId}/reactions` returns reaction counts and whether the current user selected each reaction.
- `POST /messages/{messageId}/reactions` adds a reaction; `POST /messages/{messageId}/reactions/remove` removes it. Both write routes are rate-limited and require conversation membership.

## Maintenance
- `php database/cleanup.php` removes expired stories, password-reset tokens, email-verification tokens, expired access tokens, and old rate-limit buckets. It refuses web requests. Run it daily using the hosting scheduler if available; story visibility already filters expired stories even before cleanup.


## Voice and video calls on the main host
- `POST /api/v1/calls` with `{conversation_id, type:"audio"|"video"}` creates an authenticated call for conversation members (2–8 participants).
- `GET /api/v1/calls/active?conversation_id=...` checks for an active/ringing call; `GET /api/v1/calls/{id}` returns its state and participants.
- `POST /api/v1/calls/{id}/join`, `/decline`, and `/end` update call state.
- `POST /api/v1/calls/{id}/signal` accepts bounded `offer`, `answer`, or `ice` payloads only between participants. `GET /api/v1/calls/{id}/signals?after=0` polls new records. Signaling records expire after 10 minutes.
- Signaling uses the same PHP API and MySQL database. WebRTC media is intended to flow peer-to-peer and is not uploaded to PHP. TURN relay and complete Android calling UI still need integration and live testing; do not treat calls as production-ready yet.
- Fresh installs include the call tables in `database/schema.sql`; existing installations apply `database/migrations/009_calls_signaling.sql` through the setup page or CLI migration runner.


## Mandatory phone verification via TextBee
- Registration requires an international phone number in E.164 format. The server sends a six-digit SMS code that expires after 10 minutes; only its hash is stored.
- `POST /api/v1/auth/phone/verify` accepts `phone` and `code`; `POST /api/v1/auth/phone/resend` rotates the code with a 60-second resend cooldown.
- Configure `PLUSCHAT_TEXTBEE_API_KEY` and `PLUSCHAT_TEXTBEE_DEVICE_ID` as private server environment variables, or set `textbee_api_key` and `textbee_device_id` in the server-only `config.php`. Never put the API key in this public repository or the Android APK.
- The API call uses `POST https://api.textbee.dev/api/v1/gateway/send-sms`, the `x-api-key` header, and a `deviceId` in the JSON body. Server logs record only HTTP status and a short provider error code, not credentials, phone numbers, SMS codes, or raw responses.
- HTTP 200 means TextBee accepted/queued the SMS; it does not guarantee delivery. The paired Android device must be enabled and online with SMS permission granted.
