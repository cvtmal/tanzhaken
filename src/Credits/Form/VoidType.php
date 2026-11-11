<?php

declare(strict_types=1);

namespace App\Credits\Form;

use App\Credits\Service\CreditLedger;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Void confirmation form (CRD-5, PD2): optional `reason`, hidden `from`. Bound to {@see VoidData}.
 */
final class VoidType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', TextType::class, [
                'label' => 'credits.form.reason.label',
                'help' => 'credits.form.reason.help',
                'required' => false,
                'attr' => ['maxlength' => CreditLedger::REASON_MAX],
            ])
            ->add('from', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VoidData::class,
            'translation_domain' => 'credits',
        ]);
    }
}
