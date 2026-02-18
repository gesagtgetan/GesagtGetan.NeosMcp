<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Service;

use GesagtGetan\NeosMcp\Service\RedirectService;
use Neos\Flow\Tests\UnitTestCase;
use Neos\RedirectHandler\Redirect;
use Neos\RedirectHandler\Storage\RedirectStorageInterface;
use PHPUnit\Framework\MockObject\MockObject;

class RedirectServiceTest extends UnitTestCase
{
    private RedirectService $subject;
    private RedirectStorageInterface&MockObject $redirectStorage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redirectStorage = $this->createMock(RedirectStorageInterface::class);
        $this->subject = new RedirectService($this->redirectStorage);
    }

    // ── listRedirects ───────────────────────────────────────────────

    /**
     * @test
     */
    public function listRedirectsFiltersWithMatchParameter(): void
    {
        $matching = new Redirect('blog/old-post', 'blog/new-post', 301);
        $nonMatching = new Redirect('about', 'company/about', 301);
        $this->redirectStorage->method('getAll')->willReturn($this->generatorFrom([$matching, $nonMatching]));

        $result = $this->subject->listRedirects(match: 'old');

        self::assertCount(1, $result);
        self::assertSame('blog/old-post', $result[0]['sourceUriPath']);
    }

    /**
     * @test
     */
    public function listRedirectsMatchFilterIsCaseInsensitive(): void
    {
        $redirect = new Redirect('Blog/Old-Post', 'blog/new-post', 301);
        $this->redirectStorage->method('getAll')->willReturn($this->generatorFrom([$redirect]));

        $result = $this->subject->listRedirects(match: 'OLD');

        self::assertCount(1, $result);
    }

    /**
     * @test
     */
    public function listRedirectsMatchesTargetUriPath(): void
    {
        $redirect = new Redirect('source', 'target-with-keyword', 301);
        $this->redirectStorage->method('getAll')->willReturn($this->generatorFrom([$redirect]));

        $result = $this->subject->listRedirects(match: 'keyword');

        self::assertCount(1, $result);
    }

    /**
     * @test
     */
    public function listRedirectsRespectsLimit(): void
    {
        $redirects = [
            new Redirect('a', 'b', 301),
            new Redirect('c', 'd', 301),
            new Redirect('e', 'f', 301),
        ];
        $this->redirectStorage->method('getAll')->willReturn($this->generatorFrom($redirects));

        $result = $this->subject->listRedirects(limit: 2);

        self::assertCount(2, $result);
    }

    // ── createRedirect ──────────────────────────────────────────────

    /**
     * @test
     */
    public function createRedirectRejectsEmptySource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1739800001);

        $this->subject->createRedirect('', 'target');
    }

    /**
     * @test
     */
    public function createRedirectRejectsBlankSource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1739800001);

        $this->subject->createRedirect('   ', 'target');
    }

    /**
     * @test
     */
    public function createRedirectRejectsEmptyTarget(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1739800002);

        $this->subject->createRedirect('source', '');
    }

    /**
     * @test
     */
    public function createRedirectRejectsIdenticalPaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1739800003);

        $this->subject->createRedirect('same/path', 'same/path');
    }

    /**
     * @test
     */
    public function createRedirectRejectsIdenticalPathsWithLeadingSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1739800003);

        $this->subject->createRedirect('/same/path', 'same/path');
    }

    /**
     * @test
     */
    public function createRedirectThrowsWhenStorageReturnsEmpty(): void
    {
        $this->redirectStorage->method('addRedirect')->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1739800004);

        $this->subject->createRedirect('source', 'target');
    }

    // ── removeRedirect ──────────────────────────────────────────────

    /**
     * @test
     */
    public function removeRedirectThrowsWhenNotFound(): void
    {
        $this->redirectStorage->method('getOneBySourceUriPathAndHost')->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1739800005);

        $this->subject->removeRedirect('nonexistent');
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * @param list<Redirect> $redirects
     *
     * @return \Generator<Redirect>
     */
    private function generatorFrom(array $redirects): \Generator
    {
        yield from $redirects;
    }
}
