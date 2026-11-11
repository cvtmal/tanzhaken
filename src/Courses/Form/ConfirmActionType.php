<?php

declare(strict_types=1);

namespace App\Courses\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Uid\Uuid;

/**
 * A field-less form for token-only actions (archive, extend, reinstate, remove; P3): it carries
 * only the CSRF token (default id `submit`, stateless, `config/packages/csrf.yaml`).
 *
 * Several forms share the course page, so each is created with
 * `FormFactoryInterface::createNamed(ConfirmActionType::formName($action, $id), …)`; the POST
 * action rebuilds the same name to read its request. The per-row lesson and roster forms use the
 * same naming.
 */
final class ConfirmActionType extends AbstractType
{
    public static function formName(string $action, Uuid $id): string
    {
        return $action.'_'.$id->toBase58();
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => 'courses',
        ]);
    }
}
