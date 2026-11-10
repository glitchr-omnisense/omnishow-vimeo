# omnishow/vimeo

Vimeo for [omnisense/omnishow](https://github.com/glitchr-omnisense/omnishow)'s video contracts,
through its API 3.4. Every endpoint below was read in the reference on 2026-11-09
([videos](https://developer.vimeo.com/api/reference/videos),
[users](https://developer.vimeo.com/api/reference/users),
[showcases](https://developer.vimeo.com/api/reference/showcases),
[authentication](https://developer.vimeo.com/api/authentication)); no token was used, the tests run
on answers written by hand after it.

## The calls

Base `https://api.vimeo.com/`; on every call `Accept: application/vnd.vimeo.*+json;version=3.4` and
`Authorization: bearer <token>`, and `fields=` with only what is read.

| Contract | Call |
|---|---|
| the token, without one given | `POST /oauth/authorize/client`, `Authorization: basic base64(client_id:client_secret)`, JSON `{"grant_type":"client_credentials","scope":"public"}`, once per instance |
| `video()` | `GET /videos/{id}` |
| `videos()` | `GET /videos?uris=/videos/{a},/videos/{b}&per_page=<n>` (100 a call) |
| `searchVideos()` | `GET /videos?query=&page=&per_page=`, or `GET /users/{channel}/videos?query=...` |
| `channel()` | `GET /users/{id}` |
| `channelVideos()` | `GET /users/{id}/videos?sort=date&direction=desc&page=&per_page=` |
| `playlists()` | `GET /users/{id}/albums?page=&per_page=` |
| `playlist()` | `GET /users/{user}/albums/{showcase}` |
| `playlistVideos()` | `GET /users/{user}/albums/{showcase}/videos?sort=manual&page=&per_page=` |

A page's token is Vimeo's page number; the next one is given while `paging.next` is there. `per_page`
is kept within 1 and 100.

## How a video is read

| Field | Read from |
|---|---|
| the identifier | the number in `uri` (`/videos/76979871`) |
| `title`, `description`, `duration` | `name`, `description`, `duration` (seconds; 0 while Vimeo has not computed it) |
| `published` | `release_time`, else `created_time` |
| `channel` | `user.uri`, `user.name`, `user.link` |
| `thumbnails` | `pictures.sizes[]` (`link`, `width`, `height`), smallest first |
| `url`, `embedUrl`, `privateEmbedUrl` | `link`, `player_embed_url`, and the same with `dnt=1` ([player parameters](https://help.vimeo.com/hc/en-us/articles/12426260232977-Player-parameters-overview)) |
| `embeddable` | `privacy.embed`: `public` true, `private` false, `whitelist` unknown (null) |
| `privacy`, `views`, `tags`, `language` | `privacy.view`, `stats.plays`, `tags[].name`, `language` |

A user: the number in `uri`, `name`, `link` (its last segment as the handle), `bio`, `pictures`,
`created_time`, `metadata.connections.videos.total`. A showcase: `"{user}/{showcase}"` from its
`uri`, `name`, `description`, `link`, its owner, `metadata.connections.videos.total`.

## Known limits

- Vimeo's reference says a showcase's videos are listed for the showcase's owner: with a public
  token, another user's showcase may be refused.
- Whether `/users/{id}` takes a vanity name (`examplestudio`) beside the number is not stated: use
  the number.
- Search answers 503 when Vimeo disables it; that is a `ProviderException`.

## Usage policy

The package declares Vimeo's usage policy as data, in [policy.json](../policy.json)
(omnisense/contracts' schema, read at its sources on 2026-11-10): rate limits per minute and per
user, by the account's plan (25 on Free to 2,500 on Enterprise, twice that with field filtering):
unknown to the provider, which does not know the plan; a site declares its own; charging users needs
Vimeo's written authorisation, content stored only as the application needs it, no datasets, no AI
training, no credit asked but no implied endorsement. Through omnishow's registry every call is held
to it: a site's own limit (its plan's) is counted, a 429 and its `Retry-After` hold every next call
until the wait is over (`UsageLimitReachedException`), and a site may tighten the policy in its
configuration (`'policy' => [...]`), never loosen it. What the terms do not say is declared unknown;
the sources and the details are in the file's `provenance`.

## Errors

A 404 is null, or an empty page. Any other error status is a `ProviderException` naming the path:
an invalid token answers 401 (`error_code` 8003). The token never appears in a message.

## Verified, and not

- Verified (the omnishow harness, PHP 8.4, 2026-11-09): every call above recorded as sent (query,
  headers, the token's body), each answer's reading, the errors, `BareTest`, PHPStan at max.
- Not verified live: no token was used.
