# omnishow/vimeo

[omnisense/omnishow](https://github.com/glitchr-omnisense/omnishow)'s Vimeo, under any PHP host, through
[Vimeo's API 3.4](https://developer.vimeo.com/api/reference). It implements the **video**
contracts:

- **a video's record** (`GET /videos/{id}`) and several in one call (`GET /videos?uris=`): title,
  description, duration, thumbnails, the date released, its owner, tags, plays, privacy, whether it
  may be embedded, its page, its player (`player_embed_url`, the hash of an unlisted video kept)
  and the player with `dnt=1`, which stops it collecting session data and analytics;
- **a search** (`GET /videos?query=`), or within a user's videos;
- **a user as a channel** (`GET /users/{id}`) and **its videos**, newest first;
- **its showcases as playlists** (`GET /users/{id}/albums`, `.../albums/{id}/videos` in their manual
  order), named `"{user}/{showcase}"`.

```php
use Omnishow\Model\VideoRef;
use Omnishow\Vimeo\VimeoFactory;

$vimeo = (new VimeoFactory($httpClient))->create(['client_id' => getenv('VIMEO_CLIENT_ID'), 'client_secret' => getenv('VIMEO_CLIENT_SECRET')]);
$video = $vimeo->video(VideoRef::fromUrl('https://vimeo.com/76979871'));
echo $video->embed(private: true);   // https://player.vimeo.com/video/76979871?h=...&dnt=1
$showcases = $vimeo->playlists('101');
```

Options: `token` (an access token), or `client_id` and `client_secret` (the application's: an
unauthenticated token is asked once, `POST /oauth/authorize/client`, public data only); `api`
(`https://api.vimeo.com/`). See [docs/index.md](docs/index.md).

Vimeo's live events and its type `live` need the owner's token: a live video is read as a video, its
broadcast not described (no live contract here).

## Install

```sh
composer require omnishow/vimeo
```

It requires `omnisense/omnishow` `^1.0`, installed with it. A real call needs: **a Vimeo access
token**, or an app's client id and secret. With no account, its tests run on recorded answers (a
`MockHttpClient`), and
[omnisense/omnishow](https://github.com/glitchr-omnisense/omnishow#install-and-a-first-call)'s
README has a first call that runs without one.

## Usage policy

The package declares Vimeo's usage policy as data, in [policy.json](policy.json)
(omnisense/contracts' schema, read at its sources on 2026-11-10): rate limits per minute and per
user, by the account's plan (25 on Free to 2,500 on Enterprise, twice that with field filtering):
unknown to the provider, which does not know the plan; a site declares its own; charging users needs
Vimeo's written authorisation, content stored only as the application needs it, no datasets, no AI
training, no credit asked but no implied endorsement. Through omnishow's registry every call is held
to it: a site's own limit (its plan's) is counted, a 429 and its `Retry-After` hold every next call
until the wait is over (`UsageLimitReachedException`), and a site may tighten the policy in its
configuration (`'policy' => [...]`), never loosen it. What the terms do not say is declared unknown;
the sources and the details are in the file's `provenance`.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Vimeo.com, Inc. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts written by hand as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Vimeo's terms ([Terms of
Service](https://vimeo.com/terms), [API License Agreement](https://vimeo.com/legal/service-terms/api)),
for obtaining whatever they require (an application, a token, a rate limit to respect), and for any
use made of what it returns. The authors publish it free of charge, make no commercial use of it,
and accept no liability for how it is used (see [LICENSE](LICENSE)).

## Licence

MIT.
