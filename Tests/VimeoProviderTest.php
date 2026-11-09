<?php

namespace Omnishow\Vimeo\Tests;

use Omnishow\Exception\InvalidConfigException;
use Omnishow\Exception\NotSupportedException;
use Omnishow\Exception\ProviderException;
use Omnishow\Model\Playlist;
use Omnishow\Model\Video;
use Omnishow\Model\VideoQuery;
use Omnishow\Model\VideoRef;
use Omnishow\Vimeo\VimeoFactory;
use Omnishow\Vimeo\VimeoProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Vimeo API 3.4's answers shaped after its reference
 * (developer.vimeo.com/api/reference, read on 2026-11-09), values written by
 * hand: no token was used, nothing here was captured from Vimeo. Every
 * request is recorded as sent, its query and headers included.
 */
final class VimeoProviderTest extends TestCase
{
    private const API = 'https://api.vimeo.com/';
    private const VIDEO_FIELDS = 'fields=uri%2Cname%2Cdescription%2Clink%2Cduration%2Ccreated_time%2Crelease_time%2Cpictures.sizes%2Cplayer_embed_url%2Cprivacy.view%2Cprivacy.embed%2Cstats.plays%2Cuser.uri%2Cuser.name%2Cuser.link%2Ctags%2Clanguage';

    /** @var list<array{0: string, 1: array<mixed>}> "METHOD url" and the options as sent */
    private array $requests = [];

    public function testAVideoWithItsPlayerAndItsDoNotTrackPlayer(): void
    {
        $video = $this->provider()->video(VideoRef::vimeo(76979871));

        self::assertSame(['GET '.self::API.'videos/76979871?'.self::VIDEO_FIELDS], $this->urls());
        self::assertSame(['Accept: application/vnd.vimeo.*+json;version=3.4', 'Authorization: bearer not-a-real-vimeo-token'], $this->headers(0, 'accept', 'authorization'));
        self::assertNotNull($video);
        self::assertSame(['The New Vimeo Player', 62, '2013-10-15T14:08:29+00:00', 1520, 'anybody', true, ['player'], 'en'], [$video->title, $video->duration, $video->published?->format(\DATE_ATOM), $video->views, $video->privacy, $video->embeddable, $video->tags, $video->language]);
        self::assertSame(['https://vimeo.com/76979871', 'https://player.vimeo.com/video/76979871?h=8272103f6e', 'https://player.vimeo.com/video/76979871?h=8272103f6e&dnt=1'], [$video->url, $video->embedUrl, $video->privateEmbedUrl]);
        self::assertSame(['101', 'Example Studio', 'https://vimeo.com/examplestudio'], [$video->channel?->id, $video->channel?->title, $video->channel?->url]);
        self::assertSame([100, 640, 1920], array_map(static fn ($image) => $image->width, $video->thumbnails));
        self::assertNull($this->provider()->video(VideoRef::vimeo(1)), 'a 404 is not known');
    }

    public function testSeveralVideosInOneCall(): void
    {
        $videos = $this->provider()->videos([VideoRef::vimeo(76979871), VideoRef::vimeo(3), VideoRef::vimeo(22439234)]);

        self::assertSame(['GET '.self::API.'videos?uris=/videos/76979871%2C/videos/3%2C/videos/22439234&per_page=3&'.self::VIDEO_FIELDS], $this->urls());
        self::assertSame([['76979871', true], ['22439234', null]], array_map(static fn (Video $v) => [$v->ref->id, $v->embeddable], $videos), 'the order asked, the unknown one left out, a whitelist embed unknown here');
    }

    public function testAVideoOfAnotherPlatformIsRefused(): void
    {
        $this->expectException(NotSupportedException::class);
        $this->provider()->video(VideoRef::youtube('aqz-KE-bpKQ'));
    }

    public function testASearchEverywhereOrInAUsersVideos(): void
    {
        $page = $this->provider()->searchVideos(new VideoQuery('player'));
        $this->provider()->searchVideos(new VideoQuery('player', '101', null, 5, '2'));

        self::assertSame([
            'GET '.self::API.'videos?query=player&page=1&per_page=10&'.self::VIDEO_FIELDS,
            'GET '.self::API.'users/101/videos?query=player&page=2&per_page=5&'.self::VIDEO_FIELDS,
        ], $this->urls());
        self::assertSame(['76979871'], array_map(static fn (Video $v) => $v->ref->id, $page->items));
        self::assertNull($page->next);
    }

    public function testAUserAsAChannelAndItsVideosNewestFirst(): void
    {
        $provider = $this->provider();
        $channel = $provider->channel('101');
        $page = $provider->channelVideos('101', null, 2);

        self::assertSame([
            'GET '.self::API.'users/101?fields=uri%2Cname%2Clink%2Cbio%2Cpictures.sizes%2Ccreated_time%2Cmetadata.connections.videos.total',
            'GET '.self::API.'users/101/videos?sort=date&direction=desc&page=1&per_page=2&'.self::VIDEO_FIELDS,
        ], $this->urls());
        self::assertNotNull($channel);
        self::assertSame(['101', 'Example Studio', 'examplestudio', 42, '2010-01-05T09:00:00+00:00'], [$channel->id, $channel->title, $channel->handle, $channel->videos, $channel->published?->format(\DATE_ATOM)]);
        self::assertSame(['76979871', '22439234'], array_map(static fn (Video $v) => $v->ref->id, $page->items));
        self::assertSame(['2', 42], [$page->next, $page->total], 'the next page number while paging.next is there');
    }

    public function testShowcasesAsPlaylists(): void
    {
        $provider = $this->provider();
        $playlists = $provider->playlists('101');
        $showcase = $provider->playlist('101/5000001');
        $videos = $provider->playlistVideos('101/5000001', '2', 1);

        self::assertSame([
            'GET '.self::API.'users/101/albums?page=1&per_page=25&fields=uri%2Cname%2Cdescription%2Clink%2Ccreated_time%2Cpictures.sizes%2Cmetadata.connections.videos.total%2Cuser.uri%2Cuser.name%2Cuser.link',
            'GET '.self::API.'users/101/albums/5000001?fields=uri%2Cname%2Cdescription%2Clink%2Ccreated_time%2Cpictures.sizes%2Cmetadata.connections.videos.total%2Cuser.uri%2Cuser.name%2Cuser.link',
            'GET '.self::API.'users/101/albums/5000001/videos?sort=manual&page=2&per_page=1&'.self::VIDEO_FIELDS,
        ], $this->urls());
        self::assertSame([['101/5000001', 'Showreel', 2], ['101/5000002', 'Behind the scenes', 7]], array_map(static fn (Playlist $p) => [$p->id, $p->title, $p->count], $playlists->items));
        self::assertSame(['Showreel', 'https://vimeo.com/showcase/5000001', 'Example Studio'], [$showcase?->title, $showcase?->url, $showcase?->channel?->title]);
        self::assertSame([[1, '22439234']], array_map(static fn (Video $v) => [$v->position, $v->ref->id], $videos->items), 'the position counts the pages before');
        self::assertNull($provider->playlist('5000001'), 'a showcase is named under its owner');
        self::assertCount(3, $this->requests, 'nothing asked for a name Vimeo cannot reach');
    }

    public function testAnUnauthenticatedTokenAskedOnceWithTheClientsIdAndSecret(): void
    {
        $provider = $this->provider(['token' => null, 'client_id' => 'example-client', 'client_secret' => 'example-secret']);
        $provider->video(VideoRef::vimeo(76979871));
        $provider->channel('101');

        self::assertSame(['POST '.self::API.'oauth/authorize/client', 'GET '.self::API.'videos/76979871?'.self::VIDEO_FIELDS, 'GET '.self::API.'users/101?fields=uri%2Cname%2Clink%2Cbio%2Cpictures.sizes%2Ccreated_time%2Cmetadata.connections.videos.total'], $this->urls());
        self::assertSame(['Authorization: basic '.base64_encode('example-client:example-secret'), 'Content-Type: application/json'], $this->headers(0, 'authorization', 'content-type'));
        self::assertSame('{"grant_type":"client_credentials","scope":"public"}', $this->requests[0][1]['body'] ?? null);
        self::assertSame(['Authorization: bearer unauthenticated-token-1234'], $this->headers(2, 'authorization'));
    }

    public function testARefusedTokenIsAProviderErrorWithoutTheToken(): void
    {
        try {
            $this->provider(['token' => 'refused-token-123'])->video(VideoRef::vimeo(76979871));
            self::fail('401');
        } catch (ProviderException $e) {
            self::assertStringStartsWith('Vimeo answered 401 for GET /videos/76979871. {', $e->getMessage());
            self::assertStringContainsString('"error_code": 8003', $e->getMessage());
            self::assertStringNotContainsString('refused-token-123', $e->getMessage());
        }
    }

    public function testATokenOrTheClientsCredentialsAreRequired(): void
    {
        $capabilities = $this->provider()->capabilities();
        self::assertSame(['video'], $capabilities->families());
        self::assertSame(['video', 'videoSearch', 'channels', 'playlists'], $capabilities->contracts());

        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "vimeo" provider needs: token, or client_id and client_secret.');
        (new VimeoFactory(new MockHttpClient()))->create(['client_id' => 'example-client']);
    }

    /** @return list<string> */
    private function urls(): array
    {
        return array_map(static fn (array $request) => $request[0], $this->requests);
    }

    /** @return list<string> the request's headers of these names, as sent */
    private function headers(int $request, string ...$names): array
    {
        $headers = $this->requests[$request][1]['headers'] ?? [];
        $kept = [];
        foreach (\is_array($headers) ? $headers : [] as $header) {
            if (\is_string($header) && \in_array(strtolower(explode(':', $header, 2)[0]), $names, true)) {
                $kept[] = $header;
            }
        }
        sort($kept);

        return $kept;
    }

    /** @param array<string, mixed> $options */
    private function provider(array $options = []): VimeoProvider
    {
        $f = __DIR__.'/Fixtures/';
        $routes = [
            '~^POST .*/oauth/authorize/client$~' => [200, 'token.json'],
            '~^GET .*/videos/76979871\?~' => [200, 'video-76979871.json'],
            '~^GET .*/videos\?uris=~' => [200, 'videos-batch.json'],
            '~^GET .*/videos\?query=~' => [200, 'search.json'],
            '~^GET .*/users/101\?~' => [200, 'user-101.json'],
            '~^GET .*/users/101/videos\?~' => [200, 'user-videos-1.json'],
            '~^GET .*/users/101/albums\?~' => [200, 'albums.json'],
            '~^GET .*/users/101/albums/5000001\?~' => [200, 'album-5000001.json'],
            '~^GET .*/users/101/albums/5000001/videos\?~' => [200, 'album-videos-2.json'],
        ];
        $http = new MockHttpClient(function (string $method, string $url, array $sent) use ($routes, $f): MockResponse {
            $this->requests[] = [$method.' '.$url, $sent];
            if (\in_array('Authorization: bearer refused-token-123', \is_array($sent['headers'] ?? null) ? $sent['headers'] : [], true)) {
                return new MockResponse((string) file_get_contents($f.'error-401.json'), ['http_code' => 401]);
            }
            foreach ($routes as $pattern => [$status, $file]) {
                if (preg_match($pattern, $method.' '.$url)) {
                    return new MockResponse((string) file_get_contents($f.$file), ['http_code' => $status]);
                }
            }

            return new MockResponse((string) file_get_contents($f.'error-404.json'), ['http_code' => 404]);
        });
        $provider = (new VimeoFactory($http))->create(array_filter($options + ['token' => 'not-a-real-vimeo-token'], static fn ($value) => null !== $value));
        self::assertInstanceOf(VimeoProvider::class, $provider);

        return $provider;
    }
}
