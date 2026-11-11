<?php

declare(strict_types=1);

namespace Phil\RedirectsBundle\Tests\Unit\Application;

use Phil\RedirectsBundle\Application\Contract\NotFoundFilter;
use Phil\RedirectsBundle\Application\Filter\DeclaredCrawlerFilter;
use Phil\RedirectsBundle\Application\NotFoundHit;
use Phil\RedirectsBundle\Application\NotFoundRecorder;
use Phil\RedirectsBundle\Tests\Unit\Fixture\InMemoryNotFoundLog;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

#[CoversClass(NotFoundRecorder::class)]
#[CoversClass(NotFoundHit::class)]
#[CoversClass(DeclaredCrawlerFilter::class)]
final class NotFoundRecorderTest extends TestCase
{
    public function testARequestIsLoggedWithTheHostOfItsReferrerOnly(): void
    {
        $log = new InMemoryNotFoundLog();
        $clock = new MockClock('2026-05-01');

        self::assertTrue(new NotFoundRecorder($log, $clock, [], [], 1024, 50)->record(new NotFoundHit('H.Example', '/a%c3%a9/', 'https://Search.Example/q?private=1', 'Firefox')));
        self::assertEquals([['h.example', '/a%C3%A9', 'search.example', $clock->now(), 50]], $log->recorded);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function referrers(): iterable
    {
        yield 'none' => [null, null];
        yield 'relative' => ['/a', null];
        yield 'invalid host' => ['https://exa_mple/', null];
        yield 'android app' => ['android-app://com.example.app/', 'com.example.app'];
        yield 'malformed' => ['http:///x', null];
        yield 'upper case and a final dot' => ['https://Partner.Example./a', 'partner.example'];
        yield 'an international host' => ['https://bücher.example/a', 'xn--bcher-kva.example'];
        yield 'credentials' => ['https://user:secret@evil.example/', null];
        yield 'an IP address' => ['https://127.0.0.1/', null];
        // parse_url() read partner.example; a browser reads the backslash as a slash, and the host as evil.example.
        yield 'a backslash' => ['https://evil.example\\@partner.example/', 'evil.example'];
        yield 'no scheme' => ['//partner.example/', null];
        yield 'a scheme in capitals' => ['HTTPS://partner.example/', 'partner.example'];
        // A scheme is the start of the referrer: after other characters, it makes no address.
        yield 'a scheme after other characters' => [str_repeat('0', 18).'x.partner.example://a/', null];
    }

    #[DataProvider('referrers')]
    public function testTheHostOfAReferrer(?string $referrer, ?string $host): void
    {
        $log = new InMemoryNotFoundLog();
        new NotFoundRecorder($log, new MockClock())->record(new NotFoundHit('h.example', '/a', $referrer, 'Firefox'));

        self::assertSame($host, $log->recorded[0][2]);
        self::assertSame(NotFoundRecorder::DEFAULT_CAPACITY, $log->recorded[0][4]);
    }

    public function testWhatIsNeverLogged(): void
    {
        $log = new InMemoryNotFoundLog();
        $recorder = new NotFoundRecorder($log, new MockClock(), [], ['/_wdt', '/.well-known/'], 10);

        self::assertFalse($recorder->record(new NotFoundHit('h.example', '/'.str_repeat('a', 10))));
        self::assertTrue($recorder->record(new NotFoundHit('h.example', '/'.str_repeat('a', 9))));
        self::assertFalse($recorder->record(new NotFoundHit('h.example', '/_wdt/abc')));
        self::assertFalse($recorder->record(new NotFoundHit('h.example', '/.well-known/x')));
        self::assertTrue($recorder->record(new NotFoundHit('h.example', '/.well')));
        self::assertFalse($recorder->record(new NotFoundHit('h.example', 'abc')));
        self::assertFalse($recorder->record(new NotFoundHit('h.example', '/a b')));
        self::assertFalse($recorder->record(new NotFoundHit('h.example', "/a\x7F")));
        self::assertCount(2, $log->recorded);
        self::assertSame(1024, NotFoundRecorder::DEFAULT_MAX_PATH_LENGTH);
    }

    public function testAFilterKeepsARequestOut(): void
    {
        $log = new InMemoryNotFoundLog();
        $never = new class implements NotFoundFilter {
            public int $asked = 0;

            public function ignores(NotFoundHit $hit): bool
            {
                ++$this->asked;

                return '/secret' === $hit->path;
            }
        };
        $recorder = new NotFoundRecorder($log, new MockClock(), [$never, new DeclaredCrawlerFilter()]);

        self::assertFalse($recorder->record(new NotFoundHit('h.example', '/secret', userAgent: 'Firefox')));
        self::assertFalse($recorder->record(new NotFoundHit('h.example', '/a', userAgent: 'curl/8.0')));
        self::assertTrue($recorder->record(new NotFoundHit('h.example', '/a', userAgent: 'Firefox')));
        self::assertSame(3, $never->asked);
        self::assertCount(1, $log->recorded);
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function userAgents(): iterable
    {
        yield 'none' => [null, true];
        yield 'blank' => ['  ', true];
        yield 'browser' => ['Mozilla/5.0 (Macintosh) AppleWebKit/605.1.15 Version/18.0 Safari/605.1.15', false];
        yield 'search engine' => ['Mozilla/5.0 (compatible; Googlebot/2.1)', true];
        yield 'capitals' => ['BINGBOT', true];
        yield 'script' => ['python-requests/2.31', true];
        yield 'headless' => ['Mozilla/5.0 HeadlessChrome/120', true];
        yield 'extra' => ['Mozilla/5.0 Example-Audit', true];
    }

    #[DataProvider('userAgents')]
    public function testTheDeclaredCrawlers(?string $userAgent, bool $ignored): void
    {
        self::assertSame($ignored, new DeclaredCrawlerFilter(['EXAMPLE-AUDIT'])->ignores(new NotFoundHit('h', '/', userAgent: $userAgent)));
    }

    public function testTheBuiltInWordsStayWithExtraOnes(): void
    {
        self::assertTrue(new DeclaredCrawlerFilter(['other'])->ignores(new NotFoundHit('h', '/', userAgent: 'spider')));
        self::assertFalse(new DeclaredCrawlerFilter()->ignores(new NotFoundHit('h', '/', userAgent: 'Example-Audit')));
    }
}
