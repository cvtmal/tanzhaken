<?php

declare(strict_types=1);

namespace App\Students\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Create and edit a student (STU-1): first and last name, optional email and notes for the
 * teacher only. Bound to {@see StudentData}. The email is the student's, so the browser does
 * not offer the teacher's own address.
 */
final class StudentType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'form.first_name.label',
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'form.last_name.label',
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('email', EmailType::class, [
                'label' => 'form.email.label',
                'help' => 'form.email.help',
                'required' => false,
                'attr' => ['autocomplete' => 'off'],
            ])
            ->add('notes', TextareaType::class, [
                'label' => 'form.notes.label',
                'help' => 'form.notes.help',
                'required' => false,
                'attr' => ['rows' => 4],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => StudentData::class,
            'translation_domain' => 'students',
        ]);
    }
}
