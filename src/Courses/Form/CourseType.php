<?php

declare(strict_types=1);

namespace App\Courses\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * New / edit course form (D1/C3), fields in the design's order. Weekday (ISO 1–7, Mo–Su) and
 * duration render as #2's segmented tiles (`attr.class: segmented`, `form/theme.html.twig`).
 * Duration offers 45/60/75/90 plus the course's current value when it differs (P7, option
 * `current_duration`). Bound to {@see CourseData}.
 */
final class CourseType extends AbstractType
{
    public const DURATIONS = [45, 60, 75, 90];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $weekdays = [];
        foreach (range(1, 7) as $weekday) {
            $weekdays['course.form.weekday.choice.'.$weekday] = $weekday;
        }

        $durations = self::DURATIONS;
        $current = $options['current_duration'];
        if (\is_int($current) && !\in_array($current, $durations, true)) {
            $durations[] = $current;
            sort($durations);
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'course.form.name.label',
            ])
            ->add('weekday', ChoiceType::class, [
                'label' => 'course.form.weekday.label',
                'choices' => $weekdays,
                'expanded' => true,
                'attr' => ['class' => 'segmented'],
            ])
            ->add('localStartTime', TimeType::class, [
                'label' => 'course.form.local_start_time.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('durationMinutes', ChoiceType::class, [
                'label' => 'course.form.duration.label',
                'choices' => array_combine(array_map(strval(...), $durations), $durations),
                'choice_translation_domain' => false,
                'expanded' => true,
                'attr' => ['class' => 'segmented'],
            ])
            ->add('location', TextType::class, [
                'label' => 'course.form.location.label',
                'required' => false,
            ])
            ->add('startsOn', DateType::class, [
                'label' => 'course.form.starts_on.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
            ])
            ->add('endsOn', DateType::class, [
                'label' => 'course.form.ends_on.label',
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'required' => false,
            ])
            ->add('description', TextareaType::class, [
                'label' => 'course.form.description.label',
                'required' => false,
                'attr' => ['placeholder' => 'course.form.description.placeholder'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CourseData::class,
            'translation_domain' => 'courses',
            'current_duration' => null,
        ]);
        $resolver->setAllowedTypes('current_duration', ['null', 'int']);
    }
}
