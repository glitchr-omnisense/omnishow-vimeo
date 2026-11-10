# Changelog

## Unreleased (1.x)

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
