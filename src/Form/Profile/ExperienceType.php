<?php

declare(strict_types=1);

namespace App\Form\Profile;

use App\Entity\Profile\Experience;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExperienceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('title', TextType::class, ['label' => 'Job title', 'row_attr' => ['class' => 'field']])
            ->add('company', TextType::class, ['label' => 'Company', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('location', TextType::class, ['label' => 'Location', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('startDate', TextType::class, ['label' => 'Start', 'required' => false, 'attr' => ['placeholder' => 'Apr 2023'], 'row_attr' => ['class' => 'field field-short']])
            ->add('endDate', TextType::class, ['label' => 'End', 'required' => false, 'attr' => ['placeholder' => 'Present'], 'row_attr' => ['class' => 'field field-short']])
            ->add('tech', TextType::class, ['label' => 'Tech stack', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 2], 'row_attr' => ['class' => 'field field-wide']])
            ->add('bullets', CollectionType::class, CollectionOptions::for(BulletType::class, '__bullet__', 'Bullet', [], 'Bullets'))
            ->add('projects', CollectionType::class, CollectionOptions::for(ExperienceProjectType::class, '__proj__', 'Project', [], 'Projects in this role'))
            ->add('position', HiddenType::class, ['empty_data' => '0', 'attr' => ['data-collection-position' => '']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Experience::class]);
    }
}
