# Development

```bash
# Core
cd packages/core && composer install && vendor/bin/pest

# Laravel adapter (uses the local core package)
cd packages/laravel
composer config repositories.core '{"type":"path","url":"../core","options":{"symlink":true}}'
composer require testpilot/core:@dev --no-update && composer update
vendor/bin/pest

# Dashboard
cd ui && npm install
npm run dev     # http://localhost:5173 with built-in sample data (no Laravel needed)
npm run build   # writes packages/laravel/dist/app.{js,css} — commit the result
```

Mutation-testing tests need PCOV or Xdebug; without one the pipeline reports "mutation testing skipped" and the test asserts that behaviour.

Releases: tag the monorepo, then split `packages/core` and `packages/laravel` into read-only repositories (e.g. with `symplify/monorepo-builder` or `splitsh-lite`) for Packagist.
