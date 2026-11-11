<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Reset-password form: one new password, no confirmation field (#4 D7); the theme's show/hide
 * toggle replaces it. Bound to {@see ResetPasswordData}.
 */
final class ResetPasswordType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordType::class, [
            'label' => 'reset.password.label',
            'help' => 'reset.password.help',
            'attr' => ['autocomplete' => 'new-password'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ResetPasswordData::class,
            'translation_domain' => 'account',
        ]);
    }
}
