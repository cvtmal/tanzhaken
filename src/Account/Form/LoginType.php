<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Login form (A1/A3). Only rendered: the firewall's form_login reads `_username`, `_password`
 * and `_csrf_token` (token id `authenticate`) from the raw request. Create it unnamed so the
 * field names stay as they are: `createNamed('', LoginType::class, ['_username' => $last])`.
 */
final class LoginType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('_username', EmailType::class, [
                'label' => 'login.email.label',
                'attr' => [
                    'autocomplete' => 'email',
                    'placeholder' => 'login.email.placeholder',
                ],
            ])
            ->add('_password', PasswordType::class, [
                'label' => 'login.password.label',
                'attr' => ['autocomplete' => 'current-password'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_field_name' => '_csrf_token',
            'csrf_token_id' => 'authenticate',
            'translation_domain' => 'account',
        ]);
    }
}
