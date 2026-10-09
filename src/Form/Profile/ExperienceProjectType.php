<?php

declare(strict_types=1);

namespace App\Form\Profile;

use App\Entity\Profile\ExperienceProject;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ExperienceProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Project', 'row_attr' => ['class' => 'field']])
            ->add('period', TextType::class, ['label' => 'Period', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('url', TextType::class, ['label' => 'URL', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('tech', TextType::class, ['label' => 'Tech stack', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 2], 'row_attr' => ['class' => 'field field-wide']])
            ->add('bullets', CollectionType::class, CollectionOptions::for(BulletType::class, '__pbullet__', 'Bullet', [], 'Bullets'))
            ->add('position', HiddenType::class, ['empty_data' => '0', 'attr' => ['data-collection-position' => '']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ExperienceProject::class]);
    }
}
