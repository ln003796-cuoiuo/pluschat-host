# Pluschat critical feature status and release checklist

## Changes on this feature branch

- Android chat composer now has a microphone control, bounded AAC/M4A recording, client-side encryption through the existing encrypted-attachment path, and playback for received audio attachments.
- Android chat composer now has an emoji insertion picker and a GIPHY search flow that downloads a selected GIF and sends it through the encrypted-attachment path; received GIF attachments can be opened in an animation preview.
- The canonical `database/schema.sql` includes tables for public one-time prekey metadata, conversation/channel key epochs and encrypted key envelopes, media metadata, and backup audit records.
- The legacy TextBee/SMS migration is retired; phone verification is not part of registration.
- CLI scripts were added for encrypted database backups, encrypted uploads backups, and isolated restore drills. They require `mysqldump`, `mysql`, `tar`, `gpg`, PHP PDO MySQL, shell process support, and host-provided environment variables.

These are code changes, **not a claim that the release is production-ready**. No live host, MySQL restore, Android build, or device-level test has been performed in this branch.

## SQL and rollout

For a fresh database, use the canonical `database/schema.sql`. It does not create the SMS verification-code table. For an existing installation, back up first, then apply `database/migrations/010_crypto_media_backups.sql`. Migration `008_textbee_phone_verification.sql` is intentionally retired. Existing legacy phone columns/tables can remain unused; don't require them in auth.

## Required security work before release

- Private identity keys, signing keys, ratchet state, and plaintext must remain on trusted clients. The server stores public keys and encrypted key envelopes only.
- Verify identity/prekey signatures on clients; reject unsigned or invalid bundles; provide a fingerprint/safety-number comparison.
- Consume one-time prekeys atomically. Prevent replay, enforce authorization on all key-envelope reads/writes, and rotate group epoch keys after membership changes.
- Group and channel E2EE is **not enabled merely by creating these tables**. The group/channel client protocol and server routes must be implemented and tested; plaintext group/channel writes must be rejected before calling this complete.
- GIF search requires the owner to set the GIPHY API key in server configuration. GIFs are sent as encrypted attachments; verify real-device GIF playback and large-file limits.
- Thiings assets are **not bundled yet**. Confirm the exact asset license for the intended app use before downloading or packaging them. Use the existing emoji picker until licensed sticker assets are added.
- Voice recording/playback is implemented in the Android source but still needs a successful build and device tests, including permission denial, cancellation, interrupted recording, size limits, and attachment failure recovery.

## Backup and restore

Configure these environment variables in the host's protected cron environment, never in Git:
`PLUSCHAT_DB_HOST`, `PLUSCHAT_DB_NAME`, `PLUSCHAT_DB_USER`, `PLUSCHAT_DB_PASSWORD`, `PLUSCHAT_BACKUP_DIR`, and `PLUSCHAT_BACKUP_PASSPHRASE` (at least 20 characters).

- Schedule `php scripts/backup-database.php` and `php scripts/backup-uploads.php` daily.
- The backup scripts encrypt output with GPG AES-256, write mode-restricted files outside the web root, log status/checksum in `backup_runs`, and retain encrypted artifacts for 90 days.
- Keep a second copy on separate protected storage. A local backup on the same host is not disaster recovery.
- Test database restoration only into a pre-created isolated database with `PLUSCHAT_RESTORE_TARGET_DB` set to a name different from the source, then run `php scripts/restore-database.php /path/to/backup.sql.gpg`.
- Test upload restoration only into a new isolated directory, using `php scripts/restore-uploads.php /path/to/backup.tar.gz.gpg /tmp/pluschat-restore-test`.
- Confirm row counts, foreign keys, checksums, and attachment readability after restore. A successful dump alone is not a verified backup.
- E2EE means database backups cannot restore lost client private keys. A separate recovery-key/device-transfer design is still required; never put client private keys in ordinary server backups.

## Acceptance checks still required

1. Run SQL on a disposable MySQL instance and verify all foreign keys and API health.
2. Run PHP syntax/static checks and authenticated API tests.
3. Build Android locally and test voice recording/playback, GIF search/send/preview, attachment failures, and permission behavior on a real device.
4. Review the cryptographic protocol and test prekey consumption, key rotation, membership changes, replay resistance, and multi-device behavior.
5. Restore database and uploads into isolated targets, compare checksums/row counts, and document the recovery time.
6. Implement and test E2EE for groups/channels and the licensed Thiings sticker pack before marking all requested features complete.
