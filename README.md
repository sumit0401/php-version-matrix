# QloApps Version Matrix

A local web dashboard for testing any QloApps (or PrestaShop) folder on your
machine against different PHP and MySQL versions, without touching your
existing `Docker_QloApps` v1/v2 setup.

- PHP: 5.6, 7.4, 8.1, 8.4
- MySQL: 5.7, 8.0
- Point it at any folder path — each "instance" is an isolated PHP container
  + MySQL container pair on its own Docker network and its own host ports, so
  you can run several combos at once against the same or different folders.

Built with plain PHP (no framework) + [Smarty](https://www.smarty.net/) for
templating — no Node.js involved.

## Start the dashboard

One-time setup:

```bash
cd /home/sumit/www/html/Docker_QloApps/version-matrix
composer install   # installs Smarty into vendor/
```

Then just open **http://localhost/Docker_QloApps/version-matrix/** in a
browser — the native Apache serving `/home/sumit/www/html` picks it up
automatically (`index.php` at this folder's root redirects to `public/`,
where the actual app lives).

Alternative: run it standalone without Apache via PHP's built-in server —
useful if you ever move this folder outside the Apache docroot:

```bash
php -S localhost:4545 -t public
```

Open http://localhost:4545 in that case.

## Usage

1. **Build a PHP image** — click "Build" next to the PHP version you need
   (one-time per version; reused by every instance). The build runs as a
   detached background process (`nohup docker build ... &`) so the single-
   threaded PHP dev server keeps responding to other requests while it runs;
   the log panel polls and updates every ~1.5s until it finishes.
2. **Start an instance** — enter the absolute path to a QloApps folder, pick
   PHP + MySQL versions, optionally a label, click "Start instance".
3. The instance table shows the assigned app URL (`localhost:<port>`) and the
   DB's host-mapped port. Containers are named `qlomatrix-php-<id>` /
   `qlomatrix-db-<id>` if you want to `docker exec` into them directly.
4. **Stop** removes both containers and the network cleanly.

## Pointing your app at the right database

Every instance gets a fresh, empty MySQL database — same credentials on every
instance:

- Database: `qloapps`
- User / password: `qloapps` / `qloapps`
- Root password: `root`

From **inside** the PHP container, the DB host is the container name shown in
the instance row (`qlomatrix-db-<id>`) on port `3306` — that's what to put in
`config/settings.inc.php` / `app/config/parameters.php` or the QloApps
installer's "Database server address" field.

From **outside** (e.g. a MySQL GUI client on the host, or Adminer), connect to
`localhost:<dbPort>` shown in the table.

If you point an *already-installed* QloApps folder at a brand-new empty
database, it'll error out (expected) until you either re-run the installer
against the new DB or update the existing folder's DB host/credentials to
match the new instance.

## Notes

- Folders are bind-mounted read/write into the container at
  `/var/www/html` — edits on the host show up immediately, and anything the
  app writes (cache, logs, uploads) lands back on your host folder too.
- MySQL 8.0 containers are started with
  `--default-authentication-plugin=mysql_native_password` so older PHP
  `mysqli`/`pdo_mysql` drivers (5.6/7.4) can still authenticate.
- PHP 8.1/8.4 images skip the `mcrypt` extension (removed from PHP core,
  and QloApps' `defuse-crypto.phar` path doesn't need it — same as the
  existing v1/7.4 setup in `../docker-compose.yml`).
- State (which instances exist) is kept in `data/instances.json`; build logs
  and completion markers live in `data/builds/`. Both are gitignored.
- An instance in `starting` status is flipped to `running` (or `db-timeout`
  after 90s) the next time anything polls `?action=instances` — there's no
  background daemon, just an on-demand `docker exec ... mysqladmin ping`
  check done inline whenever the dashboard is open.

## Project layout

```
version-matrix/
  index.php               redirects "/version-matrix/" -> "public/"
  .htaccess               Options -Indexes + denies composer.json/lock/README
  composer.json           Smarty dependency
  images/php-<version>/   Dockerfile per PHP version (.htaccess denies direct access)
  src/Docker.php          docker CLI wrapper (build, run, logs, port allocation)
  src/State.php            JSON persistence for running instances       } each has its
  templates/dashboard.tpl  Smarty template for the page                  } own .htaccess
  vendor/, data/           composer deps / runtime state                 } denying all
  public/index.php        front controller: renders the page, or handles
                           ?action=... JSON endpoints for the JS to poll/post
  public/assets/          app.js (polling/fetch) + style.css
```

Only `public/` and the top-level `index.php` redirect are meant to be
web-reachable — since this folder sits inside the Apache docroot
(`/home/sumit/www/html`, which has `Options Indexes` + `AllowOverride All`
globally), every other directory (`src/`, `vendor/`, `data/`, `templates/`,
`templates_c/`, `images/`) has its own `.htaccess` with `Require all denied`
so things like `data/instances.json` or `vendor/` contents can't be fetched
directly by URL.
