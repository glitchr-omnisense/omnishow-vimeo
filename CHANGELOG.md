# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release.

### Release

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnishow: ^1.0`: stable versions, so `composer
  require omnishow/vimeo` installs in a project at Composer's default minimum-stability.
  `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's
  php-package.yml) is callable, and `release.yml` runs it on a tag before glitchr-studio/ci's
  php-release.yml.

### Added
- Vimeo's usage policy declared as data in `policy.json` (omnisense/contracts' schema, read on
  2026-11-10): the rate limit unknown to the provider (it is the account plan's: a site declares its
  own), charging users only with Vimeo's written authorisation, no datasets, no AI training.
  `VimeoProvider` implements `DeclaresUsagePolicyInterface`, the factory gives its JsonClient the
  provider's Backoff (a 429 and its `Retry-After` hold every call). `composer.json` names the policy
  (`extra.omnisense.policy`) and requires `omnisense/contracts`.
- The package: Vimeo for omnisense/omnishow's video contracts through its API 3.4 -
  `VideoInterface` (`GET /videos/{id}`, `GET /videos?uris=`), `VideoSearchInterface`
  (`GET /videos?query=`, a user's videos with `query=`), `ChannelInterface` (`GET /users/{id}`, its
  videos), `PlaylistInterface` (showcases); a token, or an unauthenticated one from the client's id
  and secret. Answers written by hand after the reference; no token was used.
