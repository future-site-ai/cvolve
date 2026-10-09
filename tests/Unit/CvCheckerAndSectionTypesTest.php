<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Service\Cv\CvChecker;
use Cvolve\TemplateKit\SectionTypes;
use Cvolve\TemplateKit\Util\TextDiff;
use PHPUnit\Framework\TestCase;

final class CvCheckerAndSectionTypesTest extends TestCase
{
    public function testNormalizeAcceptsLooseShapes(): void
    {
        $content = SectionTypes::normalize('experience', ['items' => [
            ['title' => ' Dev ', 'bullets' => ['one', ['text' => 'two', 'visible' => false], '']],
            'garbage',
        ]]);

        self::assertCount(1, $content['items']);
        self::assertSame('Dev', $content['items'][0]['title']);
        self::assertTrue($content['items'][0]['visible']);
        self::assertSame([['text' => 'one', 'visible' => true], ['text' => 'two', 'visible' => false]], $content['items'][0]['bullets']);
        self::assertSame('', $content['items'][0]['organization']);
    }

    public function testValidateAppliesTemplateLimits(): void
    {
        self::assertNotEmpty(SectionTypes::validate('summary', ['text' => str_repeat('a', 20)], ['maxLength' => 10]));
        $items = ['items' => [
            ['visible' => true, 'bullets' => [['text' => 'a', 'visible' => true], ['text' => 'b', 'visible' => true]]],
            ['visible' => false, 'bullets' => []],
        ]];
        self::assertSame([], SectionTypes::validate('experience', $items, ['maxItems' => 1, 'maxBullets' => 2]));
        self::assertNotEmpty(SectionTypes::validate('experience', $items, ['maxBullets' => 1]));
    }

    public function testKeywordsKeepTechTerms(): void
    {
        $keywords = (new CvChecker())->keywords('We need a C++ and Node.js developer. Node.js experience with AWS and Kubernetes; Kubernetes is key.');
        self::assertContains('node.js', $keywords);
        self::assertContains('kubernetes', $keywords);
        self::assertContains('c++', $keywords);
        self::assertNotContains('need', $keywords);
    }

    public function testTextDiff(): void
    {
        $diff = TextDiff::lines(['a', 'b', 'c'], ['a', 'x', 'c']);
        self::assertSame(['same', 'del', 'add', 'same'], array_column($diff, 'type'));
        self::assertFalse(TextDiff::hasChanges(TextDiff::lines(['a'], ['a'])));
    }
}
