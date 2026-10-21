# Pluschat: encrypted media, E2EE and backups

## Security boundary
The server may store and route client-encrypted media and key envelopes, but must never receive private identity/session keys. A schema migration does not itself implement a complete audited E2EE protocol. Android must verify signed prekeys, consume one-time prekeys, derive session keys locally, encrypt audio/media before upload, detect identity changes and rekey groups after membership changes.

## Voice, stickers and GIF
The existing attachment endpoint accepts encrypted bytes only and stores them outside the public web directory. Migration 010 adds media classification (file, voice, sticker, GIF) and optional duration. The Android client still needs recording/playback controls and must encrypt audio before upload. GIF search is already proxied by the server; keep the GIPHY key only in server configuration. Thiings assets are not Unicode emoji; verify the applicable license before bundling or distributing them.

## Key exchange and groups
Migration 010 adds a one-time public-prekey table and a recipient-device encrypted key-envelope table. Store ciphertext envelopes only. Clients must verify identity keys/signatures locally and refuse to send when a recipient has no trusted key. Group/channel envelopes are not a substitute for a reviewed group E2EE protocol. Public channel posts must not be labelled end-to-end encrypted unless readers are explicitly provisioned with keys.

## Backups
Set PLUSCHAT_BACKUP_KEY to a randomly generated secret of at least 32 characters. Set PLUSCHAT_BACKUP_DIR to a private directory outside the web root, preferably on separate storage. PHP CLI needs PDO MySQL, JSON, zlib and sodium.

Daily command:
    php database/migrate.php
    php database/backup.php

The encrypted .pcbak file is an authenticated compressed logical snapshot of database rows and encrypted upload files from storage/uploads. Schema and migrations remain versioned separately. The script keeps backups for 90 days and records hashes/status in backup_runs. It materializes rows and upload files in memory and is intended for small installations; larger deployments should additionally use host-native database backup tooling.

Restore is destructive and requires a maintenance window plus an existing schema at the matching migration level. Take a fresh backup first:
    php database/backup.php
    php database/restore.php --confirm-restore /absolute/path/to/backup.pcbak
    php tests/check-server.php

Restore replaces table rows in the selected database. Check the database name and backup path carefully. Test recovery against a separate staging database regularly, not only production.
