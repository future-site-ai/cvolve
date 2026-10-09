<?php

declare(strict_types=1);

namespace App\Form\Profile;

use App\Entity\Profile\Project;
use App\Form\LinesTransformer;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ProjectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Project', 'row_attr' => ['class' => 'field']])
            ->add('role', TextType::class, ['label' => 'Role', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('startDate', TextType::class, ['label' => 'Start', 'required' => false, 'row_attr' => ['class' => 'field field-short']])
            ->add('endDate', TextType::class, ['label' => 'End', 'required' => false, 'row_attr' => ['class' => 'field field-short']])
            ->add('url', TextType::class, ['label' => 'URL', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('tech', TextType::class, ['label' => 'Tech stack', 'required' => false, 'row_attr' => ['class' => 'field']])
            ->add('description', TextareaType::class, ['label' => 'Description', 'required' => false, 'attr' => ['rows' => 2], 'row_attr' => ['class' => 'field field-wide']])
            ->add('highlights', TextareaType::class, ['label' => 'Highlights (one per line)', 'required' => false, 'attr' => ['rows' => 4], 'row_attr' => ['class' => 'field field-wide']])
            ->add('position', HiddenType::class, ['empty_data' => '0', 'attr' => ['data-collection-position' => '']]);
        $builder->get('highlights')->addModelTransformer(new LinesTransformer());
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Project::class]);
    }
}
