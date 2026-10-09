<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Entity\AiProposal;
use App\Entity\Cv;
use App\Entity\CvVersion;
use App\Repository\TemplateRepository;
use App\Service\Cv\CvBuilder;
use App\Service\Cv\ProfileSerializer;
use App\Service\Cv\ProposalException;
use App\Service\Cv\ProposalManager;
use App\Service\Tool\CvToolbox;
use App\Service\Tool\ToolContext;
use App\Tests\DatabaseTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProposalFlowTest extends KernelTestCase
{
    use DatabaseTestTrait;

    private EntityManagerInterface $em;
    private Cv $cv;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = $this->resetDatabase();
        $this->seeder()->installPresets();

        $user = $this->createUser();
        static::getContainer()->get(ProfileSerializer::class)->apply($this->profileOf($user), [
            'personal' => ['fullName' => 'Jane Doe', 'email' => 'jane@example.com'],
            'summaries' => [['label' => 'Main', 'content' => 'Engineer with ten years of experience.']],
            'experiences' => [
                ['title' => 'Lead Engineer', 'company' => 'Acme', 'start' => '2020', 'end' => 'Present', 'bullets' => ['Shipped A', 'Shipped B', 'Shipped C']],
                ['title' => 'Engineer', 'company' => 'Globex', 'start' => '2016', 'end' => '2020', 'bullets' => ['Built X']],
            ],
        ]);
        $this->em->flush();

        $template = static::getContainer()->get(TemplateRepository::class)->findPreset('cv-software');
        \assert($template !== null);
        $this->cv = static::getContainer()->get(CvBuilder::class)->create($user, 'Test CV', $template);
        $this->em->persist($this->cv);
        $this->em->flush();
    }

    private function toolbox(): CvToolbox
    {
        return static::getContainer()->get(CvToolbox::class);
    }

    private function context(): ToolContext
    {
        return new ToolContext($this->cv->getOwner(), 'test', $this->cv);
    }

    public function testToolsProposeWithoutChangingTheCv(): void
    {
        $result = $this->toolbox()->call('set_professional_summary', ['text' => 'A brand new summary.'], $this->context());

        self::assertArrayHasKey('proposal_id', $result);
        $summary = $this->cv->getDefaultVariant()->findSectionByType('summary');
        self::assertSame('Engineer with ten years of experience.', $summary?->getContent()['text']);
    }

    public function testApplySnapshotsFirstAndChangesContent(): void
    {
        $result = $this->toolbox()->call('set_professional_summary', ['text' => 'A brand new summary.'], $this->context());
        $proposal = $this->em->find(AiProposal::class, $result['proposal_id']);
        \assert($proposal !== null);

        static::getContainer()->get(ProposalManager::class)->apply($proposal);

        self::assertSame(AiProposal::STATUS_APPLIED, $proposal->getStatus());
        self::assertSame('A brand new summary.', $this->cv->getDefaultVariant()->findSectionByType('summary')?->getContent()['text']);
        $versions = $this->em->getRepository(CvVersion::class)->findBy(['cv' => $this->cv]);
        self::assertCount(1, $versions);
        self::assertSame(CvVersion::REASON_AI, $versions[0]->getReason());
        self::assertStringContainsString('ten years', json_encode($versions[0]->getSnapshot(), \JSON_THROW_ON_ERROR));

        $this->expectException(ProposalException::class);
        static::getContainer()->get(ProposalManager::class)->apply($proposal);
    }

    public function testDiscardLeavesCvUntouched(): void
    {
        $result = $this->toolbox()->call('set_professional_summary', ['text' => 'Nope.'], $this->context());
        $proposal = $this->em->find(AiProposal::class, $result['proposal_id']);
        \assert($proposal !== null);
        static::getContainer()->get(ProposalManager::class)->discard($proposal);

        self::assertSame(AiProposal::STATUS_DISCARDED, $proposal->getStatus());
        self::assertSame('Engineer with ten years of experience.', $this->cv->getDefaultVariant()->findSectionByType('summary')?->getContent()['text']);
    }

    public function testTemplateRulesAreEnforced(): void
    {
        // cv-software allows 900 characters
        $result = $this->toolbox()->call('set_professional_summary', ['text' => str_repeat('word ', 300)], $this->context());
        self::assertStringContainsString('at most 900', $result['error'] ?? '');

        // The template has no such section type
        $result = $this->toolbox()->call('add_section', ['type' => 'nonsense'], $this->context());
        self::assertArrayHasKey('error', $result);
    }

    public function testVisibilityTargetsAndReorder(): void
    {
        $variant = $this->cv->getDefaultVariant();
        $experience = $variant->findSectionByType('experience');
        \assert($experience !== null);
        $manager = static::getContainer()->get(ProposalManager::class);

        $result = $this->toolbox()->call('deselect_items', ['section_id' => $experience->getId(), 'targets' => ['1', '0.2']], $this->context());
        $manager->apply($this->em->find(AiProposal::class, $result['proposal_id']));
        $items = $experience->getContent()['items'];
        self::assertFalse($items[1]['visible']);
        self::assertFalse($items[0]['bullets'][2]['visible']);
        self::assertTrue($items[0]['bullets'][0]['visible']);

        $ids = array_map(static fn ($s) => $s->getId(), $variant->getOrderedSections());
        $result = $this->toolbox()->call('reorder_sections', ['section_ids' => array_reverse($ids)], $this->context());
        $manager->apply($this->em->find(AiProposal::class, $result['proposal_id']));
        self::assertSame(array_reverse($ids), array_map(static fn ($s) => $s->getId(), $variant->getOrderedSections()));
    }

    public function testOtherUsersCannotTouchTheCv(): void
    {
        $intruder = $this->createUser('mallory@example.com');
        $result = $this->toolbox()->call('get_cv', ['cv_id' => $this->cv->getId()], new ToolContext($intruder, 'test'));

        self::assertSame('CV not found.', $result['error'] ?? null);
    }

    public function testAiToolsReportMissingProvider(): void
    {
        $result = $this->toolbox()->call('generate_summary_from_master', [], $this->context());
        self::assertStringContainsString('No AI provider', $result['error'] ?? '');
    }
}
