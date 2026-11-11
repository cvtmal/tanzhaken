<?php

declare(strict_types=1);

namespace App\Courses\Form;

use App\Courses\Entity\Enrollment;
use App\Courses\ReadModel\StudentOption;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Enrol form (STU-3): a student from option `students` (EnrollableStudents, D-035), the choice
 * value is the student's RFC 4122 UUID; an optional check-in label. A student who is not in the
 * list (archived, enrolled, another teacher's) is an invalid choice. Bound to {@see EnrollData}.
 */
final class EnrollType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<StudentOption> $students */
        $students = $options['students'];
        $names = [];
        foreach ($students as $student) {
            $names[$student->id->toRfc4122()] = $student->firstName.' '.$student->lastName;
        }

        $builder
            ->add('student', ChoiceType::class, [
                'label' => 'enroll.form.student.label',
                'placeholder' => 'enroll.form.student.placeholder',
                'choices' => array_keys($names),
                'choice_value' => static fn (?string $id): string => $id ?? '',
                'choice_label' => static fn (string $id): string => $names[$id],
                'choice_translation_domain' => false,
            ])
            ->add('label', TextType::class, [
                'label' => 'enroll.form.label.label',
                'help' => 'enroll.form.label.help',
                'required' => false,
                'attr' => ['maxlength' => Enrollment::LABEL_MAX],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EnrollData::class,
            'translation_domain' => 'courses',
        ]);
        $resolver->setRequired('students');
        $resolver->setAllowedTypes('students', StudentOption::class.'[]');
    }
}
