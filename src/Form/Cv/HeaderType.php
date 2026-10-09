<?php

declare(strict_types=1);

namespace App\Form\Cv;

use Cvolve\TemplateKit\SectionTypes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * The CV header (an array): name, headline, contact details and photo toggle.
 */
class HeaderType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (SectionTypes::HEADER_FIELDS as $field => $label) {
            $wide = \in_array($field, ['workAuthorization', 'extra'], true);
            $builder->add($field, $wide ? TextareaType::class : TextType::class, [
                'label' => $label,
                'required' => false,
                'empty_data' => '',
                'attr' => $wide ? ['rows' => 2] : [],
                'row_attr' => ['class' => 'field'.($wide ? ' field-wide' : '')],
            ]);
        }
        $builder->add('showPhoto', CheckboxType::class, ['label' => 'Show profile photo', 'required' => false, 'row_attr' => ['class' => 'field field-wide']]);
    }
}
