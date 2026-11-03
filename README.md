# Royal Hold'em — PHP + MySQL edition

Multiplayer Texas Hold'em with live peer-to-peer video, built to run on ordinary shared PHP hosting.

- **To install on your website:** read `DEPLOYMENT_GUIDE.md` (database → upload → config.php → install.php → HTTPS).
- **To understand how it was built:** read `HOW_IT_WAS_BUILT.md`.

Quick start (local, for developers):

```bash
cp config.sample.php config.php      # edit DB credentials
mysql -u root -p your_db < schema.sql
php -S localhost:8080                # open http://localhost:8080
```

Requirements: PHP 7.4+ (pdo_mysql, mbstring), MySQL 5.7+/MariaDB 10.3+, HTTPS for camera access.
