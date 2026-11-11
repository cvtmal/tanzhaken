<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Entity\Course;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Add-lesson and re-time form (CRS-4): date, time, optional duration. Bound to {@see LessonData}.
 */
final class LessonType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('date', DateType::class, [
                'label' => 'lesson.form.date.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('time', TimeType::class, [
                'label' => 'lesson.form.time.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('durationMinutes', IntegerType::class, [
                'label' => 'lesson.form.duration.label',
                'help' => 'lesson.form.duration.help',
                'required' => false,
                'attr' => ['min' => 1, 'max' => Course::DURATION_MAX],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LessonData::class,
            'translation_domain' => 'courses',
        ]);
    }
}
