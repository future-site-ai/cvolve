<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\ImportJob;
use App\Entity\Profile\MasterProfile;
use App\Entity\User;
use App\Message\ParseImportMessage;
use App\Service\Ai\AiTextService;
use App\Service\Ai\Prompts;
use App\Service\Cv\ProfileSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Import flow: extract text -> AI parses it into profile JSON (async) -> user reviews -> apply.
 * CVolve profile JSON files are imported directly, without AI.
 */
final class ProfileImporter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface $bus,
        private readonly AiTextService $ai,
        private readonly ProfileSerializer $serializer,
    ) {
    }

    public function start(User $user, string $kind, string $text, ?string $filename = null): ImportJob
    {
        $job = new ImportJob($user, $kind, $text, $filename);
        $this->em->persist($job);
        $this->em->flush();
        $this->bus->dispatch(new ParseImportMessage((int) $job->getId()));

        return $job;
    }

    public function parse(ImportJob $job): void
    {
        $job->start();
        $this->em->flush();

        try {
            $direct = $this->asProfileJson($job->getSourceText());
            if ($direct !== null) {
                $job->ready($direct);
            } elseif (!$this->ai->isAvailable()) {
                $job->fail('Parsing Markdown, PDF and DOCX needs an AI provider (set AI_PROVIDER in .env.local). CVolve profile JSON can be imported without AI.');
            } else {
                $data = $this->ai->completeJson(Prompts::profileParser(), $job->getSourceText());
                $job->ready($this->clean($data));
            }
        } catch (\Throwable $e) {
            $job->fail($e->getMessage());
        }
        $this->em->flush();
    }

    /**
     * @param list<string>|null $sections
     */
    public function apply(ImportJob $job, MasterProfile $profile, ?array $sections, string $mode): void
    {
        $this->serializer->apply($profile, $job->getResult() ?? [], $sections, $mode === 'merge' ? 'merge' : 'replace');
        $job->markApplied();
        $this->em->flush();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function asProfileJson(string $text): ?array
    {
        $trimmed = ltrim($text);
        if (!str_starts_with($trimmed, '{')) {
            return null;
        }
        $data = json_decode($trimmed, true);
        if (!\is_array($data) || !isset($data['personal']) && !isset($data['experiences'])) {
            return null;
        }

        return $this->clean($data);
    }

    /**
     * Keep only known keys so the review screen and apply() see a predictable shape.
     *
     * @param array<mixed> $data
     *
     * @return array<string, mixed>
     */
    private function clean(array $data): array
    {
        $out = ['personal' => \is_array($data['personal'] ?? null) ? $data['personal'] : []];
        foreach (array_keys(ProfileSerializer::SECTION_KEYS) as $key) {
            $out[$key] = \is_array($data[$key] ?? null) ? array_values(array_filter($data[$key], 'is_array')) : [];
        }

        return $out;
    }
}
