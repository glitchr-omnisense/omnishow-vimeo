<?php

namespace Omnishow\Vimeo\Tests;

use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnishow\Exception\OmnishowException;
use Omnishow\Exception\UsageLimitReachedException;
use Omnishow\Model\VideoRef;
use Omnishow\Registry;
use Omnishow\Testing\RecordedHttpClient;
use Omnishow\Vimeo\VimeoFactory;
use Omnishow\Vimeo\VimeoProvider;
use PHPUnit\Framework\TestCase;

/**
 * VimeoProvider's usage policy (policy.json) through omnishow's registry:
 * as declared, a site's limit counted with the call over it never made,
 * a 429 and its Retry-After holding every next call, a looser setting
 * refused. No network: recorded answers only.
 */
final class PolicyTest extends TestCase
{
    private const OPTIONS = ['token' => 'not-a-real-vimeo-token'];

    public function testThePolicyIsTheOneDeclared(): void
    {
        $policy = (new Registry([new VimeoFactory(RecordedHttpClient::routes([]))], ['films' => ['factory' => 'vimeo', 'options' => self::OPTIONS]]))->policy('films');

        self::assertSame('vimeo', $policy->provider);
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertFileExists(VimeoProvider::POLICY);
        self::assertNull($policy->rateLimits, 'the limit is the account\'s plan');
        self::assertSame(Permission::Contract, $policy->rights->commercial);
        self::assertFalse($policy->rights->attribution->required);
    }

    public function testASitesLimitIsCountedAndTheCallOverItIsNeverMade(): void
    {
        $http = RecordedHttpClient::routes([]);
        $registry = new Registry([new VimeoFactory($http)], ['films' => ['factory' => 'vimeo', 'options' => self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'day', 'operations' => ['video']]]]]]);

        try {
            $registry->video('films')->video(VideoRef::vimeo(76979871));
        } catch (UsageLimitReachedException $e) {
            throw $e;
        } catch (OmnishowException) {
            // Nothing recorded: the provider's own answer to that, counted all the same.
        }
        $made = \count($http->requests);
        self::assertGreaterThan(0, $made);
        try {
            $registry->video('films')->video(VideoRef::vimeo(76979871));
            self::fail('The call over the site\'s limit was made.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnishowException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/day/application/video', $e->limit?->id());
        }
        self::assertCount($made, $http->requests, 'the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = RecordedHttpClient::routes(['~.~' => [429, '{"error":"too many requests"}', ['retry-after' => ['120']]]]);
        $registry = new Registry([new VimeoFactory($http)], ['films' => ['factory' => 'vimeo', 'options' => self::OPTIONS]]);

        try {
            $registry->video('films')->video(VideoRef::vimeo(76979871));
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(120, $e->retryAfter);
            self::assertSame('vimeo answered 429 Too Many Requests: try again in 120 s.', $e->getMessage());
        }
        try {
            $registry->video('films')->video(VideoRef::vimeo(76979871));
            self::fail('A call went out while the provider asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(119, $e->retryAfter);
        }
        self::assertCount(1, $http->requests, 'the wait held the next request back');
    }

    public function testASiteCannotLoosenThePolicy(): void
    {
        $registry = new Registry([new VimeoFactory(RecordedHttpClient::routes([]))], ['films' => ['factory' => 'vimeo', 'options' => self::OPTIONS, 'policy' => ['rights' => ['commercial' => 'allowed']]]]);

        $this->expectException(InvalidPolicyException::class);
        $this->expectExceptionMessage('commercial');
        $registry->video('films');
    }
}
