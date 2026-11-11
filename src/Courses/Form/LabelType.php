<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Entity\Enrollment;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Check-in label form of a roster row. Bound to {@see LabelData}.
 */
final class LabelType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('label', TextType::class, [
            'label' => 'enroll.form.label.label',
            'help' => 'enroll.form.label.help',
            'required' => false,
            'attr' => ['maxlength' => Enrollment::LABEL_MAX],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LabelData::class,
            'translation_domain' => 'courses',
        ]);
    }
}
