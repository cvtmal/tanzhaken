<?php

declare(strict_types=1);

namespace App\Account\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimezoneType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Profile card of /account (ACC-4): display name and time zone. Bound to {@see ProfileData}.
 * Block prefix `profile` keeps its fields and errors apart from the password card's form.
 */
final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'settings.name.label',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('timezone', TimezoneType::class, [
                'label' => 'settings.timezone.label',
                'help' => 'settings.timezone.help',
                'invalid_message' => 'account.settings.timezone.invalid',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileData::class,
            'translation_domain' => 'account',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'profile';
    }
}
