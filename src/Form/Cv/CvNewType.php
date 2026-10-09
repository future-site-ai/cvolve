<?php

declare(strict_types=1);

namespace App\Form\Cv;

use App\Entity\Profile\Experience;
use App\Entity\Profile\MasterProfile;
use App\Entity\Profile\ProfileSummary;
use App\Entity\Template;
use App\Service\Cv\CvBuilder;
use Cvolve\TemplateKit\SectionTypes;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class CvNewType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var MasterProfile|null $profile */
        $profile = $options['profile'];

        $builder
            ->add('title', TextType::class, [
                'label' => 'CV title',
                'attr' => ['placeholder' => 'e.g. Backend Engineer – Fintech'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 150)],
            ])
            ->add('template', ChoiceType::class, [
                'label' => 'Template',
                'choices' => $options['templates'],
                'choice_label' => static fn (Template $t) => $t->getName().($t->isPreset() ? '' : ' (private)'),
                'choice_value' => static fn (?Template $t) => $t?->getId(),
                'expanded' => true,
                'block_prefix' => 'template_picker',
                'constraints' => [new Assert\NotNull()],
            ])
            ->add('locale', ChoiceType::class, [
                'label' => 'Language',
                'choices' => array_flip(SectionTypes::LOCALES),
                'data' => 'en',
            ])
            ->add('sections', ChoiceType::class, [
                'label' => 'Sections to include from the master profile',
                'choices' => array_combine(array_map(static fn (string $t) => SectionTypes::get($t)['label'], CvBuilder::DEFAULT_ORDER), CvBuilder::DEFAULT_ORDER),
                'multiple' => true,
                'expanded' => true,
                'data' => CvBuilder::DEFAULT_ORDER,
            ]);

        if ($profile !== null && !$profile->getSummaries()->isEmpty()) {
            $builder->add('summary', EntityType::class, [
                'class' => ProfileSummary::class,
                'choices' => $profile->getSummaries()->toArray(),
                'choice_label' => static fn (ProfileSummary $s) => $s->getLabel().' — '.mb_substr(strip_tags($s->getContent()), 0, 90).'…',
                'expanded' => true,
                'label' => 'Professional summary',
                'data' => $profile->getSummaries()->first(),
            ]);
        }
        if ($profile !== null && !$profile->getExperiences()->isEmpty()) {
            $builder->add('experiences', EntityType::class, [
                'class' => Experience::class,
                'choices' => $profile->getExperiences()->toArray(),
                'choice_label' => static fn (Experience $e) => sprintf('%s — %s (%s)', $e->getTitle(), $e->getCompany(), $e->getPeriod()),
                'multiple' => true,
                'expanded' => true,
                'label' => 'Work experience to include',
                'data' => $profile->getExperiences()->toArray(),
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['templates', 'profile']);
    }
}
