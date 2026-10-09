<?php

declare(strict_types=1);

namespace App\Form\Cv;

use App\Form\Profile\CollectionOptions;
use Cvolve\TemplateKit\SectionTypes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One entry of an "items" section; the fields come from SectionTypes.
 */
class SectionItemType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $type = $options['section_type'];
        $builder->add('visible', CheckboxType::class, ['label' => 'Show this entry', 'required' => false, 'row_attr' => ['class' => 'field field-wide item-visible']]);
        foreach (SectionTypes::fields($type) as $field => $label) {
            $multiline = \in_array($field, SectionTypes::MULTILINE_FIELDS, true);
            $builder->add($field, $multiline ? TextareaType::class : TextType::class, [
                'label' => $label,
                'required' => false,
                'empty_data' => '',
                'attr' => $multiline ? ['rows' => 2] : [],
                'row_attr' => ['class' => 'field'.($multiline ? ' field-wide' : '').(\in_array($field, ['start', 'end', 'date'], true) ? ' field-short' : '')],
            ]);
        }
        $builder->add('position', HiddenType::class, ['empty_data' => '0', 'attr' => ['data-collection-position' => '']]);
        if (SectionTypes::hasBullets($type)) {
            $builder->add('bullets', CollectionType::class, ['prototype_data' => ['visible' => true, 'text' => '']] + CollectionOptions::for(SectionBulletType::class, '__bullet__', 'Bullet', [], 'Bullets'));
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('section_type');
    }
}
