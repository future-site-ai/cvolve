<?php

declare(strict_types=1);

namespace App\Form\Profile;

use App\Entity\Profile\Skill;
use App\Entity\Profile\SkillGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SkillGroupType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, ['label' => 'Group', 'attr' => ['placeholder' => 'Back-end'], 'row_attr' => ['class' => 'field field-wide']])
            ->add('skills', CollectionType::class, CollectionOptions::for(SimpleItemType::class, '__skill__', 'Skill', [
                'data_class' => Skill::class,
                'fields' => ['name' => ['text', 'Skill', 'PHP'], 'level' => ['text', 'Level (optional)', 'Expert']],
            ], 'Skills'))
            ->add('position', HiddenType::class, ['empty_data' => '0', 'attr' => ['data-collection-position' => '']]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => SkillGroup::class]);
    }
}
