<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Service\LessonScheduler;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Cancel-lesson form: optional reason. Bound to {@see CancelLessonData}.
 */
final class CancelLessonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('reason', TextType::class, [
            'label' => 'lesson.form.reason.label',
            'required' => false,
            'attr' => ['maxlength' => LessonScheduler::REASON_MAX],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CancelLessonData::class,
            'translation_domain' => 'courses',
        ]);
    }
}
