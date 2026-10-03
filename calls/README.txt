PlusChat calls server
======================
Deploy this folder on tell.плюсчат.рф.
1. PHP 8.3+, PostgreSQL and Composer.
2. composer install --no-dev --optimize-autoloader
3. Copy .env.example to .env and set secrets outside Git.
4. Import database.sql.
5. Expose /api/calls/* through HTTPS.
6. Media is WebRTC; this service performs authentication, call lifecycle and signaling authorization. It does not carry audio/video.
7. For production group calls, put an SFU (for example a WebRTC media server) behind the same protected domain; never send media through PHP.
