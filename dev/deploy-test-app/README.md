# deploy-test-app

Minimal Laravel-shaped app without dependencies, for testing the deploy
pipeline in the dev container without running third-party code:

- `composer.json` without requirements, `package.json` with a dependency-free build script
- `artisan` handles `migrate`, `storage:link`, `optimize` and logs who ran them
- `public/index.php` returns 500 until `APP_KEY` is set in `.env` (exercises auto-rollback)

In the dev container: put it in a bare repo owned by a `git` user (shell
`git-shell`), configure the site's deploy with `git@localhost:app.git` and add
the site's deploy key to `/home/git/.ssh/authorized_keys`.
