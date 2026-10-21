# Pluschat server setup and current audit

## Secure setup

1. Create a MySQL database named `Pluschat` and grant the MySQL account `admin` (or the host's case-preserved `Admin`) privileges on it.
2. Upload the repository so that the web server document root points at the `public/` directory, with the project root and `database/` directory outside the public document root where possible. The PHP entrypoint expects `bootstrap.php` and `database/` one directory above `public/`.
3. Enable HTTPS and PHP 8.1+, PDO MySQL, Sodium, and file uploads.
4. Open the public setup page once. Enter the database password and TextBee API key. GIPHY API key is optional and can be left blank until GIF search is wanted.
5. Verify `/api/v1/health` reports `ok: true`, `database: connected`, `schema: ready`, and the expected provider statuses.
6. Remove `public/setup.php` and `public/update-database.php` after setup/update. Keep `config.php` private and never commit it.

The installer writes the DB password and provider keys to a private root-level `config.php`; they are not meant to be entered in a URL or committed to Git. TextBee's device ID is taken from `PLUSCHAT_TEXTBEE_DEVICE_ID` or the existing default. The installer uses the database name `Pluschat` and tries `admin` then `Admin`.

## Current changes in this branch

- Installer accepts and privately stores an optional GIPHY API key.
- Added authenticated server-side `GET /api/v1/gifs/search?q=...` endpoint. The GIPHY key stays on the server; results return only GIF IDs, titles, HTTPS URLs and dimensions.
- GIF search is rate limited to 30 requests per minute per source IP.
- SMS resend has an additional limit of 10 attempts per source IP per rolling 24-hour window, plus the existing 3-per-15-minute limit and per-phone cooldown.
- Health endpoint reports whether the GIPHY provider is configured without returning its key.

## Important verification gaps

A successful SQL schema or PHP syntax check is not proof that every Pluschat feature works end-to-end. Before production, test registration with email + SMS, login, password recovery, message delivery, file upload, groups/channels, device sessions, push notifications, and calls on real devices.

In particular, the server's encrypted-envelope validator only validates the shape of an AES-256-GCM envelope. It does not implement client-side key generation, secure key exchange, or prove that Android encrypts every outgoing message. End-to-end encryption is not considered complete until the Android client and key-management flow are implemented and tested together. The existence of call-signaling endpoints likewise does not prove working WebRTC audio/video; STUN/TURN and Android call integration must be tested.

The SMS daily limit is currently IP-based (rolling 24 hours), not a per-account or per-phone billing ledger. Retain the short-window and per-phone cooldown checks; consider a durable per-phone/day log before public launch to reduce circumvention via changing IP addresses.
