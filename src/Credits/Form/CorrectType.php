<?php

declare(strict_types=1);

namespace App\Credits\Form;

use App\Credits\Service\CreditLedger;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Correction form (CRD-5): every GrantType field (`amount`, `otherAmount`, `date`, `note`,
 * `from`; option `max_date`) plus an optional `reason`. Bound to {@see CorrectData}.
 */
final class CorrectType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextType::class, [
            'label' => 'credits.form.reason.label',
            'help' => 'credits.form.reason.help',
            'required' => false,
            'attr' => ['maxlength' => CreditLedger::REASON_MAX],
        ]);
    }

    public function getParent(): string
    {
        return GrantType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CorrectData::class]);
    }
}
