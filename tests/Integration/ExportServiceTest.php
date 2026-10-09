<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\ExportJob;
use App\Repository\TemplateRepository;
use App\Service\Cv\CvBuilder;
use App\Service\Export\GotenbergClient;
use App\Tests\DatabaseTestTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ExportServiceTest extends KernelTestCase
{
    use DatabaseTestTrait;

    public function testPdfAndDocxExports(): void
    {
        self::bootKernel();
        $em = $this->resetDatabase();
        $this->seeder()->installPresets();
        $user = $this->createUser();
        $this->profileOf($user)->getPersonal()->setFullName('Jane Doe')->setHeadline('Engineer');
        $em->flush();

        // Gotenberg is mocked: record what the app sends, answer with a tiny PDF
        $requests = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests) {
            $body = '';
            if ($options['body'] instanceof \Closure) {
                while ('' !== $chunk = ($options['body'])(16384)) {
                    $body .= $chunk;
                }
            } else {
                $body = (string) $options['body'];
            }
            $requests[] = ['url' => $url, 'body' => $body];

            return new MockResponse("%PDF-1.4\n1 0 obj << /Type /Page >> endobj\n2 0 obj << /Type /Page >> endobj\n%%EOF", ['http_code' => 200]);
        });
        static::getContainer()->set(GotenbergClient::class, new GotenbergClient($client, 'http://gotenberg.test'));

        $template = static::getContainer()->get(TemplateRepository::class)->findPreset('cv-software');
        \assert($template !== null);
        $cv = static::getContainer()->get(CvBuilder::class)->create($user, 'Export CV', $template);
        $em->persist($cv);
        $em->flush();

        $exports = static::getContainer()->get(\App\Service\Export\ExportService::class);

        // The messenger transport is synchronous in tests, so request() runs the job
        $pdf = $exports->request($cv->getDefaultVariant(), 'pdf');
        $em->refresh($pdf);
        self::assertSame(ExportJob::STATUS_DONE, $pdf->getStatus(), (string) $pdf->getError());
        self::assertStringStartsWith('%PDF', (string) file_get_contents((string) $exports->filePath($pdf)));
        self::assertSame('http://gotenberg.test/forms/chromium/convert/html', $requests[0]['url']);
        self::assertStringContainsString('Jane Doe', $requests[0]['body']);
        self::assertStringContainsString('paperWidth', $requests[0]['body']);
        self::assertSame(2, GotenbergClient::countPages((string) file_get_contents((string) $exports->filePath($pdf))));

        $docx = $exports->request($cv->getDefaultVariant(), 'docx');
        $em->refresh($docx);
        self::assertSame(ExportJob::STATUS_DONE, $docx->getStatus(), (string) $docx->getError());
        $zip = new \ZipArchive();
        self::assertTrue($zip->open((string) $exports->filePath($docx)));
        self::assertStringContainsString('Jane Doe', (string) $zip->getFromName('word/document.xml'));
        $zip->close();
    }

    public function testGotenbergErrorsMarkTheJobFailed(): void
    {
        self::bootKernel();
        $em = $this->resetDatabase();
        $this->seeder()->installPresets();
        $user = $this->createUser();
        static::getContainer()->set(GotenbergClient::class, new GotenbergClient(new MockHttpClient(new MockResponse('boom', ['http_code' => 503])), 'http://gotenberg.test'));
        $template = static::getContainer()->get(TemplateRepository::class)->findPreset('classic');
        \assert($template !== null);
        $cv = static::getContainer()->get(CvBuilder::class)->create($user, 'CV', $template);
        $em->persist($cv);
        $em->flush();

        $job = static::getContainer()->get(\App\Service\Export\ExportService::class)->request($cv->getDefaultVariant(), 'pdf');
        $em->refresh($job);
        self::assertSame(ExportJob::STATUS_FAILED, $job->getStatus());
        self::assertStringContainsString('503', (string) $job->getError());
    }
}
