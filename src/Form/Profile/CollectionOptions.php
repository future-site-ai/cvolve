<?php

declare(strict_types=1);

namespace App\Form\Profile;

/**
 * Options for the sortable collections in the master profile editor
 * (rendered by the "cv_collection" blocks in templates/form/theme.html.twig).
 */
final class CollectionOptions
{
    /**
     * @param array<string, mixed> $entryOptions
     *
     * @return array<string, mixed>
     */
    public static function for(string $entryType, string $prototypeName, string $addLabel, array $entryOptions = [], string $label = ''): array
    {
        return [
            'entry_type' => $entryType,
            'entry_options' => ['label' => false, 'block_prefix' => 'cv_collection_entry'] + $entryOptions,
            'allow_add' => true,
            'allow_delete' => true,
            'by_reference' => false,
            'prototype' => true,
            'prototype_name' => $prototypeName,
            'label' => $label === '' ? false : $label,
            'block_prefix' => 'cv_collection',
            'attr' => ['data-prototype-name' => $prototypeName, 'data-add-label' => $addLabel],
        ];
    }
}
