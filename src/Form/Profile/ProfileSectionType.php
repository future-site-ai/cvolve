<?php

declare(strict_types=1);

namespace App\Form\Profile;

use App\Entity\Profile\Certification;
use App\Entity\Profile\CustomSection;
use App\Entity\Profile\Education;
use App\Entity\Profile\Language;
use App\Entity\Profile\MasterProfile;
use App\Entity\Profile\ProfileSummary;
use App\Entity\Profile\Publication;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * One tab of the master profile editor: a sortable collection of one entry type.
 */
class ProfileSectionType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $section = $options['section'];
        $simple = static fn (string $class, array $fields) => ['data_class' => $class, 'fields' => $fields];

        [$type, $prototype, $add, $entryOptions] = match ($section) {
            'summaries' => [SimpleItemType::class, '__summary__', 'Summary', $simple(ProfileSummary::class, [
                'label' => ['text', 'Label', 'e.g. Software / Biomedical'],
                'content' => ['textarea', 'Summary', 'Your professional summary. **bold** allowed.', 6],
            ])],
            'experiences' => [ExperienceType::class, '__exp__', 'Job', []],
            'educations' => [SimpleItemType::class, '__edu__', 'Education', $simple(Education::class, [
                'degree' => ['text', 'Degree', 'M.Sc. Computer Science'],
                'institution' => ['text', 'Institution'],
                'location' => ['text', 'Location'],
                'startDate' => ['text', 'Start'],
                'endDate' => ['text', 'End'],
                'grade' => ['text', 'Grade'],
                'description' => ['textarea', 'Description', 'Thesis, focus, exchange programmes...'],
            ])],
            'skillGroups' => [SkillGroupType::class, '__group__', 'Skill group', []],
            'languages' => [SimpleItemType::class, '__lang__', 'Language', $simple(Language::class, [
                'name' => ['text', 'Language', 'English'],
                'level' => ['text', 'Level', 'C1 — Advanced'],
                'note' => ['text', 'Note'],
            ])],
            'certifications' => [SimpleItemType::class, '__cert__', 'Certification', $simple(Certification::class, [
                'name' => ['text', 'Certification'],
                'issuer' => ['text', 'Issuer'],
                'date' => ['text', 'Date', 'Jan 2025'],
                'credentialId' => ['text', 'Credential ID'],
                'url' => ['text', 'URL'],
            ])],
            'projects' => [ProjectType::class, '__project__', 'Project', []],
            'publications' => [SimpleItemType::class, '__pub__', 'Publication', $simple(Publication::class, [
                'title' => ['textarea', 'Title', null, 2],
                'authors' => ['textarea', 'Authors', 'A. Author, B. Author', 2],
                'venue' => ['text', 'Venue / journal'],
                'date' => ['text', 'Date'],
                'url' => ['text', 'URL / DOI'],
            ])],
            'customSections' => [SimpleItemType::class, '__custom__', 'Section', $simple(CustomSection::class, [
                'title' => ['text', 'Title', 'Volunteering'],
                'kind' => ['text', 'Kind', 'volunteering, awards, interests, soft_skills, custom'],
                'body' => ['textarea', 'Entries (one per line)', "- Volunteer Team Leader, Web Summit (2021)\n- ...", 6],
            ])],
            default => throw new \InvalidArgumentException(sprintf('Unknown profile section "%s".', $section)),
        };

        $builder->add($section, CollectionType::class, CollectionOptions::for($type, $prototype, $add, $entryOptions));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => MasterProfile::class]);
        $resolver->setRequired('section');
        $resolver->setAllowedValues('section', array_keys(MasterProfile::SECTIONS));
    }
}
