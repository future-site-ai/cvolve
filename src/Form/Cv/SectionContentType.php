<?php

declare(strict_types=1);

namespace App\Form\Cv;

use App\Form\Profile\CollectionOptions;
use Cvolve\TemplateKit\SectionTypes;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Edit one CV section (array data: title, region, and the content fields).
 */
class SectionContentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $type = $options['section_type'];
        $builder->add('title', TextType::class, ['label' => 'Heading', 'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 120)], 'row_attr' => ['class' => 'field']]);
        if (\count($options['regions']) > 1) {
            $builder->add('region', ChoiceType::class, [
                'label' => 'Column',
                'choices' => array_combine(array_map('ucfirst', $options['regions']), $options['regions']),
                'row_attr' => ['class' => 'field'],
            ]);
        }

        if (SectionTypes::isText($type)) {
            $max = $options['max_length'];
            $builder->add('text', TextareaType::class, [
                'label' => 'Text',
                'required' => false,
                'empty_data' => '',
                'help' => '**bold** and *italic* are supported.'.($max ? sprintf(' This template allows up to %d characters.', $max) : ''),
                'attr' => ['rows' => 8] + ($max ? ['data-controller' => 'char-count', 'data-char-count-max-value' => $max] : []),
                'row_attr' => ['class' => 'field field-wide'],
            ]);

            return;
        }

        $builder->add('items', CollectionType::class, ['prototype_data' => ['visible' => true, 'bullets' => []]] + CollectionOptions::for(SectionItemType::class, '__item__', 'Entry', ['section_type' => $type], 'Entries'));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired('section_type');
        $resolver->setDefaults(['regions' => ['main'], 'max_length' => null]);
    }
}
