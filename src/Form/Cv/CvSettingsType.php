<?php

declare(strict_types=1);

namespace App\Form\Cv;

use App\Entity\Cv;
use App\Entity\Template;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class CvSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var Cv $cv */
        $cv = $builder->getData();
        $builder
            ->add('title', TextType::class, ['label' => 'Title'])
            ->add('template', ChoiceType::class, [
                'label' => 'Template',
                'choices' => $options['templates'],
                'choice_label' => static fn (Template $t) => $t->getName(),
                'choice_value' => static fn (?Template $t) => $t?->getId(),
            ])
            ->add('defaultLocale', ChoiceType::class, [
                'label' => 'Main language',
                'choices' => array_combine($cv->getLocales(), $cv->getLocales()),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Cv::class]);
        $resolver->setRequired('templates');
    }
}
