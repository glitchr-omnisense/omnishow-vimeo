<?php

namespace Omnishow\Vimeo\Tests;

use Omnishow\Testing\BareTestCase;
use Omnishow\Vimeo\VimeoProvider;

/** omnishow/vimeo's classes load with omnisense/omnishow and Symfony's HTTP client contracts alone. */
final class BareTest extends BareTestCase
{
    protected static function entryClass(): string
    {
        return VimeoProvider::class;
    }

    protected static function requiredPackages(): array
    {
        return ['symfony/http-client-contracts'];
    }
}
