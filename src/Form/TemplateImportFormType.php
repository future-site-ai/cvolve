<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints as Assert;

class TemplateImportFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('package', FileType::class, [
            'label' => 'Template file',
            'help' => '.cvolve package, HTML with {{placeholders}}, Twig HTML, a JSON Resume theme (.zip or .hbs), a zip with index.html + CSS, or a finished HTML CV (converted by AI).',
            'attr' => ['accept' => '.cvolve,.zip,.html,.htm,.twig,.mustache,.hbs,.handlebars'],
            'constraints' => [new Assert\NotNull(message: 'Choose a .cvolve file.'), new Assert\File(maxSize: '20M')],
        ]);
        if ($options['allow_preset']) {
            $builder->add('scope', ChoiceType::class, [
                'label' => 'Install as',
                'choices' => ['Global preset (all users)' => 'preset', 'Private template (only me)' => 'private'],
                'expanded' => true,
                'data' => 'preset',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['allow_preset' => false]);
    }
}
