# Contributing to SimpleStats

Thanks for helping to make SimpleStats better! Bug fixes, features, tests and docs improvements are all welcome.

## Before you start

- **Bugs:** open an issue with steps to reproduce, or send a pull request with a failing test and the fix.
- **Features and larger changes:** open an issue first and describe what you want to build. That way we can agree on the approach before you spend time on it.
- **Security issues:** don't open a public issue, see [SECURITY.md](SECURITY.md).

## Local setup

SimpleStats is a regular Laravel application (Vue, Inertia.js, Tailwind CSS) on PostgreSQL. Follow the installation steps in the [README](README.md), then:

```bash
composer install
npm install
npm run dev
```

The tests run against a PostgreSQL database called `simplestats_test` (see `phpunit.xml.dist`):

```bash
composer test
```

Before you push, run the same checks as CI (Pint, PHPStan, Rector and the architecture tests):

```bash
composer check
```

`composer lint` and `composer refactor` fix the Pint and Rector findings for you.

CI runs on the self-hosted build (`composer.json`). Tests for SaaS-only features call `$this->skipUnlessSaas()` and are skipped there.

## Pull requests

- Keep a pull request focused on one change.
- Add or update tests for behavior changes.
- Make sure `composer check` and `composer test` pass.
- Follow the conventions of the surrounding code (naming, structure, comments only where they explain a non-obvious why).
- Write a short description of what changed and why.
- Add screenshots (or a short screen recording) for every change that is visible in the UI.

## The hosted version

The same codebase also powers the hosted version at [simplestats.io](https://simplestats.io). Code that only runs there sits behind `config('app.app_type') === 'saas'` and its dependencies live in `composer.saas.json`. You don't need any of that for development. Please don't use SaaS-only packages outside of that guard.

## Contributor License Agreement

Before we can merge your first pull request, you need to sign our [Contributor License Agreement](CLA.md). A bot comments on your pull request and explains how, it only takes a minute and is needed once.

You keep the copyright on your contribution. The CLA allows us to ship it both in this AGPL-licensed project and in the hosted version at simplestats.io.
