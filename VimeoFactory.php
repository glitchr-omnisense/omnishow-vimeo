<?php

namespace Omnishow\Vimeo;

use Omnishow\Config;
use Omnishow\Exception\InvalidConfigException;
use Omnishow\Http\JsonClient;
use Omnishow\ProviderFactory;
use Omnishow\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "vimeo": options token (an access token: a personal one, or one an
 * application got), or client_id and client_secret (the application's: an
 * unauthenticated token is asked once, public data only); api
 * ("https://api.vimeo.com/").
 */
final class VimeoFactory extends ProviderFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnishow.factory_name' => 'vimeo',
            'omnishow.factory_title' => 'Vimeo',
            'omnishow.required_options' => [],
            'api' => 'https://api.vimeo.com/',
            'token' => null,
            'client_id' => null,
            'client_secret' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        if (null === $c->string('token') && (null === $c->string('client_id') || null === $c->string('client_secret'))) {
            throw new InvalidConfigException('The "vimeo" provider needs: token, or client_id and client_secret.');
        }

        return new VimeoProvider(new JsonClient($this->http ?? HttpClient::create(['timeout' => 20]), 'Vimeo', ['Accept' => VimeoProvider::ACCEPT]), (string) $c->string('api'), $c->string('token'), $c->string('client_id'), $c->string('client_secret'));
    }
}
