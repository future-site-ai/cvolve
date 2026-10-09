<?php

declare(strict_types=1);

namespace App\Form\Profile;

use App\Entity\Profile\PersonalInfo;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class PersonalInfoType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fields = [
            'fullName' => ['Full name', 'text'],
            'headline' => ['Headline', 'text', 'Senior Software Engineer'],
            'email' => ['Email', 'text'],
            'phone' => ['Phone', 'text'],
            'address' => ['Address / location', 'text', 'City, Country'],
            'nationality' => ['Nationality', 'text'],
            'dateOfBirth' => ['Date of birth', 'text'],
            'website' => ['Website', 'text'],
            'linkedin' => ['LinkedIn', 'text', 'linkedin.com/in/...'],
            'github' => ['GitHub', 'text', 'github.com/...'],
            'drivingLicence' => ['Driving licence', 'text'],
            'workAuthorization' => ['Work authorisation', 'textarea'],
            'extra' => ['Other personal details', 'textarea', 'Time zone, VAT number, ...'],
        ];
        foreach ($fields as $name => [$label, $widget]) {
            $placeholder = $fields[$name][2] ?? null;
            $builder->add($name, $widget === 'textarea' ? TextareaType::class : TextType::class, [
                'label' => $label,
                'required' => false,
                'attr' => array_filter(['placeholder' => $placeholder, 'rows' => $widget === 'textarea' ? 2 : null]),
                'row_attr' => ['class' => 'field'.($widget === 'textarea' ? ' field-wide' : '')],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => PersonalInfo::class]);
    }
}
