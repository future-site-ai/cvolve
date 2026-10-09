<?php

declare(strict_types=1);

namespace App\Form\Profile;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A flat master-profile entry (education, certification, language, ...): the
 * fields are passed as options so one type serves every simple entity.
 */
class SimpleItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($options['fields'] as $name => $field) {
            [$widget, $label, $placeholder] = $field + [2 => null];
            $attr = $placeholder ? ['placeholder' => $placeholder] : [];
            if ($widget === 'textarea') {
                $attr['rows'] = $field[3] ?? 3;
            }
            $builder->add($name, $widget === 'textarea' ? TextareaType::class : TextType::class, [
                'label' => $label,
                'required' => false,
                'attr' => $attr,
                'row_attr' => ['class' => 'field field-'.$name.($widget === 'textarea' ? ' field-wide' : '')],
            ]);
        }
        $builder->add('position', HiddenType::class, ['empty_data' => '0', 'attr' => ['data-collection-position' => '']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['fields', 'data_class']);
        $resolver->setAllowedTypes('fields', 'array');
    }
}
