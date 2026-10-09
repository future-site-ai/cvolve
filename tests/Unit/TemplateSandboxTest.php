<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use Cvolve\TemplateKit\Sandbox\TemplateHelpersExtension;
use Cvolve\TemplateKit\Sandbox\TemplateSandbox;
use PHPUnit\Framework\TestCase;
use Twig\Sandbox\SecurityError;

final class TemplateSandboxTest extends TestCase
{
    private TemplateSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = new TemplateSandbox();
    }

    public function testRendersAllowedSyntaxAndMacros(): void
    {
        $source = <<<'TWIG'
            {% macro item(i) %}<li>{{ i.name|upper }}</li>{% endmacro %}
            <ul>{% for i in items|filter(i => i.name) %}{{ _self.item(i) }}{% endfor %}</ul>
            TWIG;
        $html = $this->sandbox->render($source, ['items' => [['name' => 'php'], ['name' => ''], ['name' => 'go']]]);

        self::assertStringContainsString('<li>PHP</li>', $html);
        self::assertStringContainsString('<li>GO</li>', $html);
        self::assertSame(2, substr_count($html, '<li>'));
    }

    public function testUserContentIsEscaped(): void
    {
        $html = $this->sandbox->render('<p>{{ name }}</p><p>{{ bio|md }}</p>', [
            'name' => '<script>alert(1)</script>',
            'bio' => '**Bold** <img src=x onerror=alert(1)>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('<strong>Bold</strong>', $html);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function forbiddenSources(): iterable
    {
        yield 'include tag' => ['{% include "x" %}'];
        yield 'source function' => ['{{ source("/etc/passwd") }}'];
        yield 'raw filter' => ['{{ x|raw }}'];
        yield 'constant test' => ['{% if x is constant("PHP_VERSION") %}{% endif %}'];
        yield 'block function' => ['{{ block("x") }}'];
        yield 'template_from_string' => ['{{ template_from_string("x") }}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('forbiddenSources')]
    public function testForbiddenConstructsAreRejected(string $source): void
    {
        $this->expectException(\Twig\Error\Error::class);
        $this->sandbox->render($source, ['x' => 1]);
    }

    public function testObjectsCannotBeReached(): void
    {
        $this->expectException(SecurityError::class);
        $this->sandbox->render('{{ obj.secret() }}', ['obj' => new class {
            public function secret(): string
            {
                return 'leak';
            }
        }]);
    }

    public function testHelpers(): void
    {
        self::assertSame('Apr 2023 – Present', TemplateHelpersExtension::period(['start' => 'Apr 2023', 'end' => 'Present']));
        self::assertSame('linkedin.com/in/x', TemplateHelpersExtension::prettyUrl('https://www.linkedin.com/in/x/'));
        self::assertSame('<em>it</em> and <strong>b</strong>', TemplateHelpersExtension::inlineMarkdown('*it* and **b**'));
    }
}
