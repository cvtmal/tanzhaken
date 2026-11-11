<?php

declare(strict_types=1);

namespace App\Attendance\Form;

use App\Attendance\Service\AttendanceMarker;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The form of one register row (#13, P1): the optional reason and three submit buttons,
 * `present`, `absent` and `clear`. Created named `mark_<base58 student id>`
 * (`ConfirmActionType::formName('mark', $studentId)`). Bound to {@see MarkAttendanceData}.
 */
final class MarkAttendanceType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', TextType::class, [
                'label' => 'lesson.row.reason',
                'help' => 'lesson.row.reason_help',
                'required' => false,
                'attr' => ['maxlength' => AttendanceMarker::REASON_MAX_LENGTH],
            ])
            ->add('present', SubmitType::class, ['label' => 'lesson.row.present'])
            ->add('absent', SubmitType::class, ['label' => 'lesson.row.absent'])
            ->add('clear', SubmitType::class, ['label' => 'lesson.row.clear']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => MarkAttendanceData::class,
            'translation_domain' => 'attendance',
        ]);
    }
}
