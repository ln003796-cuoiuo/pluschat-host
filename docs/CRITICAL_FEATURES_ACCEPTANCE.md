# Pluschat critical feature implementation status

This branch adds the database foundation only. It does **not** claim that client E2EE, voice UI, sticker UI, or backup operations are complete.

## Migration

Apply `database/migrations/010_crypto_media_backups.sql` after migrations 001–009 and after the base schema. It is additive/idempotent for fresh databases. Back up the current database before applying it to a live installation.

## Security requirements before release

- Private identity keys, signing keys, ratchet state, and plaintext must remain on trusted clients. The server stores public keys and encrypted key envelopes only.
- Verify identity/prekey signatures on clients; reject unsigned or invalid bundles; provide a fingerprint/safety-number comparison.
- Consume one-time prekeys atomically. Prevent replay, enforce authorization on all key-envelope reads/writes, and rotate group epoch keys after membership changes.
- Group and channel E2EE is **not enabled merely by creating these tables**. Existing group/channel message routes must reject plaintext before calling this feature complete.
- Voice recordings must be encrypted client-side before upload and treated as encrypted attachments. Media metadata must never contain keys or plaintext.
- Thiings assets require license verification before bundling. GIF search remains disabled until the owner configures a GIPHY API key.

## Backup/restore operations

- Run a database + uploads backup at least daily, store copies outside the web root and ideally on a separate host/account, encrypt backups at rest, and keep a 90-day retention policy.
- Record each run in `backup_runs`; alert on failure. Do not store backup credentials in the repository or public `config.php`.
- Perform scheduled restore drills into an isolated database and uploads directory. A successful dump alone is not a verified backup.
- E2EE means a database backup cannot restore lost client private keys. Users need a separately designed recovery-key/device-transfer flow; never place client private keys in ordinary server backups.

## Acceptance checks still required

1. Run migration on a disposable MySQL database and verify foreign keys.
2. PHP syntax/static checks and API tests.
3. Android tests for microphone permission, record/cancel/send/playback, and encrypted attachment upload.
4. Cryptographic protocol review and tests for prekey consumption, key rotation, membership changes, replay, and multi-device.
5. Restore a backup into an isolated environment and compare checksums/record counts.
