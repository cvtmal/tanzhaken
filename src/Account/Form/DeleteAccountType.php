<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Delete card of /account: the password. Bound to {@see DeleteAccountData}.
 * Block prefix `delete_account` keeps its field and errors apart from the other cards' forms.
 */
final class DeleteAccountType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordType::class, [
            'label' => 'delete.password.label',
            'help' => 'delete.password.help',
            'attr' => ['autocomplete' => 'current-password'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DeleteAccountData::class,
            'translation_domain' => 'account',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'delete_account';
    }
}
