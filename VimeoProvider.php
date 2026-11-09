<?php

namespace Omnishow\Vimeo;

use Omnishow\Capabilities;
use Omnishow\ChannelInterface;
use Omnishow\Exception\NotSupportedException;
use Omnishow\Exception\ProviderException;
use Omnishow\Http\Data;
use Omnishow\Http\JsonClient;
use Omnishow\Model\Channel;
use Omnishow\Model\Image;
use Omnishow\Model\ImageKind;
use Omnishow\Model\Page;
use Omnishow\Model\Platform;
use Omnishow\Model\Playlist;
use Omnishow\Model\Video;
use Omnishow\Model\VideoQuery;
use Omnishow\Model\VideoRef;
use Omnishow\PlaylistInterface;
use Omnishow\ProviderInterface;
use Omnishow\VideoInterface;
use Omnishow\VideoSearchInterface;

/**
 * Vimeo through its API 3.4 with an access token, or with an
 * unauthenticated one asked once from the application's client id and
 * secret (POST /oauth/authorize/client, public data only): a video
 * (GET /videos/{id}), several (GET /videos?uris=), a search (GET
 * /videos?query=, or a user's videos with query=), a user as a channel and
 * its videos, its showcases as playlists. Each call asks only the fields
 * read (fields=). A showcase is named "{user}/{showcase}": Vimeo reaches
 * it under its owner.
 */
final class VimeoProvider implements ProviderInterface, VideoInterface, VideoSearchInterface, ChannelInterface, PlaylistInterface
{
    public const ACCEPT = 'application/vnd.vimeo.*+json;version=3.4';
    private const VIDEO_FIELDS = 'uri,name,description,link,duration,created_time,release_time,pictures.sizes,player_embed_url,privacy.view,privacy.embed,stats.plays,user.uri,user.name,user.link,tags,language';
    private const USER_FIELDS = 'uri,name,link,bio,pictures.sizes,created_time,metadata.connections.videos.total';
    private const ALBUM_FIELDS = 'uri,name,description,link,created_time,pictures.sizes,metadata.connections.videos.total,user.uri,user.name,user.link';

    private ?string $bearer;

    public function __construct(private readonly JsonClient $json, private readonly string $api = 'https://api.vimeo.com/', ?string $token = null, private readonly ?string $clientId = null, private readonly ?string $clientSecret = null)
    {
        $this->bearer = $token;
    }

    public function getName(): string
    {
        return 'vimeo';
    }

    public function getTitle(): string
    {
        return 'Vimeo';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(
            images: true,
            terms: ['https://vimeo.com/terms', 'https://vimeo.com/legal/service-terms/api'],
            video: true,
            videoSearch: true,
            channels: true,
            playlists: true,
            platforms: [Platform::Vimeo],
        );
    }

    public function video(VideoRef $ref): ?Video
    {
        if (!$this->capabilities()->hosts($ref)) {
            throw NotSupportedException::video($this->getTitle(), $ref);
        }
        $answer = $this->call('videos/'.rawurlencode($ref->id), ['fields' => self::VIDEO_FIELDS]);

        return null === $answer ? null : $this->video0($answer);
    }

    public function videos(array $refs): array
    {
        $ids = [];
        foreach ($refs as $ref) {
            if (!$this->capabilities()->hosts($ref)) {
                throw NotSupportedException::video($this->getTitle(), $ref);
            }
            $ids[] = $ref->id;
        }
        $found = [];
        foreach (array_chunk(array_values(array_unique($ids)), 100) as $chunk) {
            $uris = implode(',', array_map(static fn (string $id) => '/videos/'.$id, $chunk));
            foreach ($this->call('videos', ['uris' => $uris, 'per_page' => \count($chunk), 'fields' => self::VIDEO_FIELDS])?->list('data') ?? [] as $item) {
                $video = $this->video0($item);
                $found[$video->ref->id] = $video;
            }
        }

        return array_values(array_filter(array_map(static fn (string $id) => $found[$id] ?? null, $ids)));
    }

    public function searchVideos(VideoQuery $query): Page
    {
        $path = null === $query->channel ? 'videos' : 'users/'.rawurlencode($query->channel).'/videos';

        return $this->videoPage($path, ['query' => $query->text], $query->page, $query->limit);
    }

    public function channel(string $channel): ?Channel
    {
        $answer = $this->call('users/'.rawurlencode($channel), ['fields' => self::USER_FIELDS]);

        return null === $answer ? null : $this->channel0($answer);
    }

    public function channelVideos(string $channel, ?string $page = null, int $limit = 25): Page
    {
        return $this->videoPage('users/'.rawurlencode($channel).'/videos', ['sort' => 'date', 'direction' => 'desc'], $page, $limit);
    }

    public function playlist(string $id): ?Playlist
    {
        $path = self::album($id);
        $answer = null === $path ? null : $this->call($path, ['fields' => self::ALBUM_FIELDS]);

        return null === $answer ? null : $this->playlist0($answer);
    }

    public function playlists(string $channel, ?string $page = null, int $limit = 25): Page
    {
        $number = max(1, (int) $page);
        $answer = $this->call('users/'.rawurlencode($channel).'/albums', ['page' => $number, 'per_page' => Page::limit($limit, 100), 'fields' => self::ALBUM_FIELDS]);

        return new Page(array_map($this->playlist0(...), $answer?->list('data') ?? []), self::next($answer, $number), $answer?->int('total'));
    }

    public function playlistVideos(string $id, ?string $page = null, int $limit = 25): Page
    {
        $path = self::album($id);

        return null === $path ? new Page() : $this->videoPage($path.'/videos', ['sort' => 'manual'], $page, $limit, true);
    }

    /**
     * One page of a list of videos: Vimeo's page number as the token, the
     * next one when paging.next is there.
     *
     * @param array<string, string> $query
     *
     * @return Page<Video>
     */
    private function videoPage(string $path, array $query, ?string $page, int $limit, bool $positions = false): Page
    {
        $number = max(1, (int) $page);
        $perPage = Page::limit($limit, 100);
        $answer = $this->call($path, $query + ['page' => $number, 'per_page' => $perPage, 'fields' => self::VIDEO_FIELDS]);
        $videos = [];
        foreach ($answer?->list('data') ?? [] as $index => $item) {
            $videos[] = $this->video0($item, $positions ? ($number - 1) * $perPage + $index : null);
        }

        return new Page($videos, self::next($answer, $number), $answer?->int('total'));
    }

    private function video0(Data $item, ?int $position = null): Video
    {
        $id = self::id($item->string('uri'), 'videos') ?? '0';
        $ref = VideoRef::vimeo($id);
        $embed = $item->string('player_embed_url');

        return new Video(
            ref: $ref,
            title: (string) ($item->string('name') ?? $id),
            source: 'vimeo',
            description: $item->string('description'),
            duration: $item->int('duration'),
            published: $item->dateTime('release_time') ?? $item->dateTime('created_time'),
            channel: $this->owner($item->data('user')),
            thumbnails: self::pictures($item->data('pictures')),
            url: $item->string('link'),
            embedUrl: $embed,
            embeddable: match ($item->string('privacy.embed')) {
                'public' => true,
                'private' => false,
                default => null,
            },
            tags: $item->strings('tags', 'name'),
            views: $item->int('stats.plays'),
            language: $item->string('language'),
            privacy: $item->string('privacy.view'),
            position: $position,
        );
    }

    private function channel0(Data $item): Channel
    {
        $id = self::id($item->string('uri'), 'users') ?? '0';
        $link = $item->string('link');

        return new Channel(
            platform: Platform::Vimeo,
            id: $id,
            title: (string) ($item->string('name') ?? $id),
            handle: null === $link ? null : (basename((string) parse_url($link, \PHP_URL_PATH)) ?: null),
            description: $item->string('bio'),
            thumbnails: self::pictures($item->data('pictures')),
            url: $link,
            published: $item->dateTime('created_time'),
            videos: $item->int('metadata.connections.videos.total'),
            source: 'vimeo',
        );
    }

    private function playlist0(Data $item): Playlist
    {
        $uri = (string) $item->string('uri');
        $id = preg_match('~/users/(\d+)/albums/(\d+)~', $uri, $m) ? $m[1].'/'.$m[2] : $uri;

        return new Playlist(
            platform: Platform::Vimeo,
            id: $id,
            title: (string) ($item->string('name') ?? $id),
            source: 'vimeo',
            description: $item->string('description'),
            channel: $this->owner($item->data('user')),
            thumbnails: self::pictures($item->data('pictures')),
            count: $item->int('metadata.connections.videos.total'),
            url: $item->string('link'),
            published: $item->dateTime('created_time'),
        );
    }

    private function owner(Data $user): ?Channel
    {
        $id = self::id($user->string('uri'), 'users');

        return null === $id ? null : new Channel(Platform::Vimeo, $id, (string) ($user->string('name') ?? $id), url: $user->string('link'), source: 'vimeo');
    }

    /** @return list<Image> pictures.sizes, smallest first */
    private static function pictures(Data $pictures): array
    {
        $images = [];
        foreach ($pictures->list('sizes') as $size) {
            if (null !== $url = $size->string('link')) {
                $images[] = new Image(ImageKind::Thumbnail, $url, $size->int('width'), $size->int('height'));
            }
        }
        usort($images, static fn (Image $a, Image $b) => ($a->width ?? 0) <=> ($b->width ?? 0));

        return $images;
    }

    /** The number in "/videos/76979871" or "/users/101": its identifier. */
    private static function id(?string $uri, string $collection): ?string
    {
        return null !== $uri && preg_match('~/'.$collection.'/(\d+)~', $uri, $m) ? $m[1] : null;
    }

    /** "{user}/{showcase}" to its path; null for anything else. */
    private static function album(string $id): ?string
    {
        return preg_match('~^(\d+)/(\d+)$~', trim($id), $m) ? 'users/'.$m[1].'/albums/'.$m[2] : null;
    }

    private static function next(?Data $answer, int $page): ?string
    {
        return null !== $answer && null !== $answer->string('paging.next') ? (string) ($page + 1) : null;
    }

    /** @param array<string, string|int> $query */
    private function call(string $path, array $query): ?Data
    {
        return $this->json->get(rtrim($this->api, '/').'/'.$path, $query, ['Authorization' => 'bearer '.$this->bearer()]);
    }

    /** The token given, or the unauthenticated one asked once with the client's id and secret. */
    private function bearer(): string
    {
        if (null !== $this->bearer) {
            return $this->bearer;
        }
        $answer = $this->json->request('POST', rtrim($this->api, '/').'/oauth/authorize/client', ['json' => ['grant_type' => 'client_credentials', 'scope' => 'public']], [
            'Authorization' => 'basic '.base64_encode($this->clientId.':'.$this->clientSecret),
            'Content-Type' => 'application/json',
        ]);

        return $this->bearer = $answer?->string('access_token') ?? throw ProviderException::answered('Vimeo', 'POST /oauth/authorize/client', 200, 'No access_token in the answer.');
    }
}
