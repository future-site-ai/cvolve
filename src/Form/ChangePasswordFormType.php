<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

class ChangePasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['require_current']) {
            $builder->add('currentPassword', PasswordType::class, [
                'label' => 'Current password',
                'constraints' => [new UserPassword(message: 'This is not your current password.')],
            ]);
        }
        $builder->add('plainPassword', RepeatedType::class, [
            'type' => PasswordType::class,
            'first_options' => ['label' => 'New password', 'attr' => ['autocomplete' => 'new-password']],
            'second_options' => ['label' => 'Repeat password', 'attr' => ['autocomplete' => 'new-password']],
            'invalid_message' => 'The passwords do not match.',
            'constraints' => [
                new Assert\NotBlank(message: 'Please enter a password.'),
                new Assert\Length(min: 8, max: 4096, minMessage: 'Your password should be at least {{ limit }} characters.'),
                new Assert\NotCompromisedPassword(skipOnError: true),
            ],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['require_current' => false]);
    }
}
