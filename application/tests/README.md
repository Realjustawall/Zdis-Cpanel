# Reproducing the PHP integration checks

Use a disposable, empty MySQL/MariaDB database. These tests create users, groups and messages, revoke sessions, change settings, and run cron. Never point them at production.

Requirements: PHP 8.2+ with pdo_mysql, mbstring, fileinfo, cURL, GD, OpenSSL; Python 3.10+ with cryptography; Node.js and Playwright only for browser checks.

1. Copy config.example.php to a private config.php and configure the isolated test database. For loopback HTTP tests only, set origin to http://127.0.0.1:8087 and secure_cookies to false. Keep production on HTTPS with secure_cookies=true.
2. Install using `php bin/install.php`. Create username `admin`, email `admin@example.test`, test-only password `TestAdmin12345!`.
3. Start the server from application: `php -d upload_max_filesize=32M -d post_max_size=34M -S 127.0.0.1:8087 -t public public/index.php`.
4. Run `ZDIS_TEST_DISPOSABLE=1 ZDIS_TEST_URL=http://127.0.0.1:8087 ZDIS_PHP_BIN=php python3 tests/integration.py`.
5. For the browser test, install Playwright in a test workspace and run `ZDIS_TEST_DISPOSABLE=1 ZDIS_TEST_URL=http://127.0.0.1:8087 node tests/browser-smoke.mjs` from a workspace where Playwright resolves. Set ZDIS_CHROMIUM if supplying a Chromium binary. The browser test uses synthetic microphone/camera devices and allows loopback ICE for an isolated local test.

The integration check is intended for a fresh fixture database. Re-running against already-created test fixtures can produce conflict responses; create another disposable database rather than deleting an existing deployment.

PHP syntax check: `find app bin public -name '*.php' -exec php -l {} \;`.

The PHP development server is a testing tool. Production uses Apache/LiteSpeed PHP in cPanel, not php -S.


Additional suite: run `tests/extended.py` after the base integration suite, on the same disposable instance. Set `test_allow_local_http=true` ONLY in that disposable config; the suite starts a loopback webhook receiver on port 8091, checks actual cURL delivery and its HMAC. It creates distinct QA fixtures and must not run on production. Production must not allow local webhook destinations. Before independent suites, the development harness clears rate_limits in its disposable database so unrelated login scenarios do not exhaust the same bucket; production rate limits are not raised. Recreate the database before repeating the suites.
