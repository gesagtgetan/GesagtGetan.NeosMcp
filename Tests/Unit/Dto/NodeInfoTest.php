<?php

declare(strict_types=1);

namespace GesagtGetan\NeosMcp\Tests\Unit\Dto;

use GesagtGetan\NeosMcp\Dto\NodeInfo;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Test;

class NodeInfoTest extends UnitTestCase
{
    #[Test]
    public function displayTitleCombinesTypeAndTitleWhenTitlePropertyExists(): void
    {
        $node = new NodeInfo('id', 'Neos.Demo:Document.Page', 'page', false, ['title' => 'Welcome to Neos']);

        self::assertSame('Neos.Demo:Document.Page - Welcome to Neos', $node->jsonSerialize()['displayTitle']);
    }

    #[Test]
    public function displayTitleFallsBackToTypeWhenNoTitleProperty(): void
    {
        $node = new NodeInfo('id', 'Neos.Demo:Content.Image', null, false, ['alternativeText' => 'A photo']);

        self::assertSame('Neos.Demo:Content.Image', $node->jsonSerialize()['displayTitle']);
    }

    #[Test]
    public function displayTitleFallsBackToTypeWhenTitleIsEmptyOrNonString(): void
    {
        $emptyTitle = new NodeInfo('id', 'Neos.Demo:Content.Text', null, false, ['title' => '']);
        $nonStringTitle = new NodeInfo('id', 'Neos.Demo:Content.Text', null, false, ['title' => 42]);

        self::assertSame('Neos.Demo:Content.Text', $emptyTitle->jsonSerialize()['displayTitle']);
        self::assertSame('Neos.Demo:Content.Text', $nonStringTitle->jsonSerialize()['displayTitle']);
    }
}
