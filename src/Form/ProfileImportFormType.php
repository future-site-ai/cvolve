<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

class ProfileImportFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('file', FileType::class, [
                'label' => 'Upload a file',
                'required' => false,
                'help' => 'Markdown (.md), text, CVolve profile JSON, an existing CV as PDF or DOCX.',
                'attr' => ['accept' => '.md,.markdown,.txt,.json,.pdf,.docx'],
                'constraints' => [new Assert\File(maxSize: '10M')],
            ])
            ->add('text', TextareaType::class, [
                'label' => 'Or paste text / Markdown',
                'required' => false,
                'attr' => ['rows' => 10, 'placeholder' => "# Jane Doe\n\n## Work Experience\n..."],
            ]);
    }
}
