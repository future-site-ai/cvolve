<?php

declare(strict_types=1);

namespace App\Form\Cv;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;

class SectionBulletType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('visible', CheckboxType::class, ['label' => 'Show', 'required' => false, 'row_attr' => ['class' => 'bullet-visible']])
            ->add('text', TextareaType::class, ['label' => false, 'empty_data' => '', 'attr' => ['rows' => 2]])
            ->add('position', HiddenType::class, ['empty_data' => '0', 'attr' => ['data-collection-position' => '']]);
    }
}
