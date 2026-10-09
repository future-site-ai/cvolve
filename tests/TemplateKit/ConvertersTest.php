<?php

declare(strict_types=1);

namespace App\Tests\TemplateKit;

use Cvolve\TemplateKit\Import\ConversionException;
use Cvolve\TemplateKit\Import\HandlebarsConverter;
use Cvolve\TemplateKit\Import\HtmlCleaner;
use Cvolve\TemplateKit\Import\MustacheConverter;
use Cvolve\TemplateKit\Import\SchemaInference;
use Cvolve\TemplateKit\Render\SampleCv;
use Cvolve\TemplateKit\Sandbox\TemplateSandbox;
use PHPUnit\Framework\TestCase;

final class ConvertersTest extends TestCase
{
    private function render(string $twig): string
    {
        $schema = ['regions' => ['sidebar', 'main'], 'sections' => [
            'summary' => ['region' => 'main'], 'experience' => ['region' => 'main'], 'skills' => ['region' => 'sidebar'],
            'languages' => ['region' => 'sidebar'], 'education' => ['region' => 'main'], 'projects' => ['region' => 'main'],
            'certifications' => ['region' => 'sidebar'], 'publications' => ['region' => 'main'], 'custom' => ['region' => 'sidebar'],
        ]];

        return (new TemplateSandbox())->render($twig, SampleCv::context($schema));
    }

    public function testMustacheSectionsLoopsAndScopes(): void
    {
        $twig = (new MustacheConverter())->toTwig(<<<'HTML'
            <h1>{{header.name}}</h1>
            {{#regions.main}}
              <section><h2>{{title}}</h2>
                {{#is_summary}}<p>{{{text}}}</p>{{/is_summary}}
                {{#items}}<h3>{{title}} — {{organization}} ({{period}})</h3><ul>{{#bullets}}<li>{{{.}}}</li>{{/bullets}}</ul>{{/items}}
                {{^items}}<i>no items</i>{{/items}}
              </section>
            {{/regions.main}}
            {{! a comment }}
            HTML);
        $html = $this->render($twig);

        self::assertStringContainsString('<h1>Alex Morgan</h1>', $html);
        self::assertStringContainsString('<h2>Work Experience</h2>', $html);
        self::assertStringContainsString('Senior Software Engineer — Northwind Health (Mar 2021 – Present)', $html);
        self::assertStringContainsString('<li><strong>Patient portal:</strong>', $html);
        self::assertStringContainsString('<strong>reliable web platforms</strong>', $html);
        // The summary section has no items: the inverted block shows; the outer "title" resolves for education degree items? No: education uses degree
        self::assertStringContainsString('<i>no items</i>', $html);
        self::assertStringNotContainsString('a comment', $html);
    }

    public function testMustacheEscapesAndReportsErrors(): void
    {
        $twig = (new MustacheConverter())->toTwig('<p>{{header.name}}</p><p>{% not twig %}</p>');
        self::assertStringContainsString('{% not twig %}', (new TemplateSandbox())->render($twig, ['header' => ['name' => '<b>x</b>']]));
        self::assertStringContainsString('&lt;b&gt;', (new TemplateSandbox())->render($twig, ['header' => ['name' => '<b>x</b>']]));

        try {
            (new MustacheConverter())->toTwig("{{#items}}\n{{/bullets}}{{> partial}}");
            self::fail('Expected a conversion error.');
        } catch (ConversionException $e) {
            $all = implode(' ', $e->errors);
            self::assertStringContainsString('closes {{#items}}', $all);
            self::assertStringContainsString('partials', $all);
        }
    }

    public function testHandlebarsJsonResumeTheme(): void
    {
        $result = (new HandlebarsConverter())->toTwig(<<<'HBS'
            <h1>{{resume.basics.name}}</h1><p>{{basics.label}}</p>
            {{#if resume.work.length}}{{/if}}
            {{#each resume.work}}
              <div class="job"><b>{{position}}</b> at {{name}} {{formatDate startDate}}–{{formatDate endDate}}
                {{#if highlights}}<ul>{{#each highlights}}<li>{{this}}</li>{{/each}}</ul>{{/if}}
                {{#unless summary}}<i>-</i>{{else}}<p>{{summary}}</p>{{/unless}}
                <span>{{../basics.name}}</span><span>{{@index}}</span>
              </div>
            {{/each}}
            {{#resume.skills}}<span class="skill">{{name}}: {{join keywords}}</span>{{/resume.skills}}
            {{> footer}}
            {{unknownHelper basics.email}}
            HBS, ['partials/footer.hbs' => '<footer>{{basics.email}}</footer>']);

        $html = $this->render($result['twig']);
        self::assertStringContainsString('<h1>Alex Morgan</h1>', $html);
        self::assertStringContainsString('<p>Senior Software Engineer</p>', $html);
        self::assertStringContainsString('<b>Senior Software Engineer</b> at Northwind Health Mar 2021–Present', $html);
        self::assertStringContainsString('<li>Cut API response times by 45% by reworking the caching layer.</li>', $html);
        self::assertStringContainsString('<span>0</span>', $html);
        self::assertStringContainsString('Back-end: PHP, Symfony, Python, Django, REST', $html);
        self::assertStringContainsString('<footer>alex.morgan@example.com</footer>', $html);
        self::assertStringContainsString('unknownHelper', implode(' ', $result['warnings']));
    }

    public function testHtmlCleanerRemovesActiveContent(): void
    {
        $clean = (new HtmlCleaner())->clean(<<<'HTML'
            <!DOCTYPE html><html><head><title>My CV</title>
            <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Inter">
            <style>@import url('https://x/y.css'); body { color: red; background: url(https://evil/x.png); } @font-face { font-family: X; src: url(https://f/x.woff2); }</style>
            <script>alert(1)</script></head>
            <body><div onclick="steal()">Hi <a href="javascript:alert(1)">x</a><img src="photo.jpg"><img src="{{header.photo}}"></div></body></html>
            HTML);

        self::assertSame('My CV', $clean['title']);
        self::assertStringNotContainsString('script', $clean['body']);
        self::assertStringNotContainsString('onclick', $clean['body']);
        self::assertStringNotContainsString('javascript:', $clean['body']);
        self::assertStringNotContainsString('photo.jpg', $clean['body']);
        self::assertStringContainsString('{{header.photo}}', $clean['body']);
        self::assertStringContainsString('color: red', $clean['css']);
        self::assertStringNotContainsString('@import', $clean['css']);
        self::assertStringNotContainsString('evil', $clean['css']);
        self::assertStringNotContainsString('@font-face', $clean['css']);
        self::assertNotEmpty($clean['warnings']);
    }

    public function testSchemaInference(): void
    {
        $schema = (new SchemaInference())->infer('{% for s in regions.sidebar %}{% endfor %}{% for s in regions.main %}{% endfor %}');
        self::assertSame(['sidebar', 'main'], $schema['regions']);
        self::assertSame('sidebar', $schema['sections']['skills']['region']);
        self::assertSame('main', $schema['sections']['experience']['region']);

        $schema = (new SchemaInference())->infer('{% for j in (resume.work ?? work)|as_list %}{% endfor %}{{ resume.basics.summary }}');
        self::assertSame(['summary', 'experience'], array_keys($schema['sections']));
    }
}
