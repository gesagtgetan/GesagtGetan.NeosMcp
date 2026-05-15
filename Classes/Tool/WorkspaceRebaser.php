<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tool;

use GesagtGetan\NeosMcp\ContentRepositoryFacade;
use GesagtGetan\NeosMcp\Dto\WithRebaseWarning;
use Neos\ContentRepository\Core\Feature\WorkspaceCommandSkipped;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Exception\WorkspaceRebaseFailed;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * Rebases the workspace before every tool call so reads reflect the latest live
 * state and writes don't target stale nodes, and surfaces conflicting unpublished
 * changes as a `_rebaseWarning` on the tool response.
 */
#[Flow\Proxy(false)]
final readonly class WorkspaceRebaser
{
    public function __construct(
        private ContentRepositoryFacade $contentRepository,
        private WorkspaceName $workspaceName,
    ) {
    }

    /**
     * Returns a conflict warning string when the rebase fails due to conflicting
     * unpublished changes, or null on success (including the already-up-to-date case).
     */
    public function rebase(): ?string
    {
        try {
            $this->contentRepository->handle(RebaseWorkspace::create($this->workspaceName));
        } catch (WorkspaceCommandSkipped) {
            // Workspace is already up-to-date — nothing to do.
        } catch (WorkspaceRebaseFailed $e) {
            $conflicts = $e->conflictingEvents->map(static function ($conflict): array {
                $entry = ['message' => $conflict->getException()->getMessage()];
                $nodeId = $conflict->getAffectedNodeAggregateId();
                if ($nodeId !== null) {
                    $entry['nodeAggregateId'] = $nodeId->value;
                }

                return $entry;
            });

            return 'Workspace rebase failed due to conflicts with live. '
                . 'The workspace may contain stale data. '
                . 'Consider discarding conflicting changes via discardWorkspaceChanges. '
                . 'Conflicts: ' . json_encode($conflicts, JSON_THROW_ON_ERROR);
        }

        return null;
    }

    /**
     * @template T of WithRebaseWarning
     *
     * @param T $result
     *
     * @return T
     */
    public function withWarning(WithRebaseWarning $result, ?string $warning): WithRebaseWarning
    {
        return $warning === null ? $result : $result->withRebaseWarning($warning);
    }
}
