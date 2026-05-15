<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Tool;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Dto\WriteResult;
use GesagtGetan\NeosMcp\Tool\WorkspaceRebaser;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandSkipped;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;

class WorkspaceRebaserTest extends UnitTestCase
{
    private ContentRepositoryFacade&MockObject $contentRepository;
    private WorkspaceRebaser $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contentRepository = $this->createMock(ContentRepositoryFacade::class);
        $this->subject = new WorkspaceRebaser($this->contentRepository, WorkspaceName::fromString('test-ws'));
    }

    #[Test]
    public function rebaseReturnsNullWhenSuccessful(): void
    {
        $this->contentRepository->expects(self::once())->method('handle')->with(self::isInstanceOf(RebaseWorkspace::class));

        self::assertNull($this->subject->rebase());
    }

    #[Test]
    public function rebaseReturnsNullForAlreadyUpToDateWorkspace(): void
    {
        $this->contentRepository->method('handle')->willThrowException(new WorkspaceCommandSkipped());

        self::assertNull($this->subject->rebase());
    }

    #[Test]
    public function withWarningAttachesWarningWhenProvided(): void
    {
        $input = new WriteResult(nodeAggregateId: 'node-1');

        $result = $this->subject->withWarning($input, 'something went wrong');

        self::assertInstanceOf(WriteResult::class, $result);
        self::assertSame('something went wrong', $result->getRebaseWarning());
        self::assertSame('node-1', $result->nodeAggregateId);
    }

    #[Test]
    public function withWarningReturnsResultUnchangedWhenWarningIsNull(): void
    {
        $input = new WriteResult(nodeAggregateId: 'node-1');

        $result = $this->subject->withWarning($input, null);

        self::assertSame($input, $result);
        self::assertNull($result->getRebaseWarning());
    }
}
