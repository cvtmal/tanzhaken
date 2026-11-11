<?php

declare(strict_types=1);

namespace App\Credits\Form;

use App\Credits\Entity\CreditGrant;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Add-credits form (CRD-1, E2): `amount` (expanded radios 10 / 5 / other), `otherAmount`,
 * `date` (single_text, HTML `max` = option `max_date`, today `Y-m-d` in the teacher's zone),
 * `note`, hidden `from`. Bound to {@see GrantData}. CorrectType extends it.
 */
final class GrantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $maxDate */
        $maxDate = $options['max_date'];

        $builder
            ->add('amount', ChoiceType::class, [
                'label' => 'credits.form.amount.label',
                'expanded' => true,
                'multiple' => false,
                'choices' => [
                    'credits.form.amount.choice.full' => GrantData::AMOUNT_FULL,
                    'credits.form.amount.choice.half' => GrantData::AMOUNT_HALF,
                    'credits.form.amount.choice.other' => GrantData::AMOUNT_OTHER,
                ],
            ])
            ->add('otherAmount', IntegerType::class, [
                'label' => 'credits.form.other_amount.label',
                'required' => false,
                'attr' => ['min' => 1, 'max' => GrantData::OTHER_MAX, 'inputmode' => 'numeric'],
            ])
            ->add('date', DateType::class, [
                'label' => 'credits.form.date.label',
                'help' => 'credits.form.date.help',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => ['max' => $maxDate],
            ])
            ->add('note', TextType::class, [
                'label' => 'credits.form.note.label',
                'help' => 'credits.form.note.help',
                'required' => false,
                'attr' => ['maxlength' => CreditGrant::NOTE_MAX],
            ])
            ->add('from', HiddenType::class);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => GrantData::class,
            'translation_domain' => 'credits',
        ]);
        $resolver->setRequired('max_date');
        $resolver->setAllowedTypes('max_date', 'string');
    }
}
