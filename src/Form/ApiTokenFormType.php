<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ApiTokenFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Token name',
                'attr' => ['placeholder' => 'e.g. Claude Desktop'],
                'constraints' => [new Assert\NotBlank(), new Assert\Length(max: 80)],
            ])
            ->add('expires', ChoiceType::class, [
                'label' => 'Expires',
                'choices' => ['In 30 days' => '+30 days', 'In 90 days' => '+90 days', 'In 1 year' => '+1 year', 'Never' => 'never'],
                'data' => '+90 days',
            ]);
    }
}
