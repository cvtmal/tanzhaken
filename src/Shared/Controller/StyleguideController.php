<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every component in every state (S5: dev and test only).
 */
final class StyleguideController extends AbstractController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route('/_styleguide', name: '_styleguide', env: ['dev', 'test'], methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        if ($request->query->getBoolean('flashes')) {
            $this->addFlash('success', $this->translator->trans('flash.success', [], 'styleguide'));
            $this->addFlash('error', $this->translator->trans('flash.error', [], 'styleguide'));
        }

        return $this->render('styleguide/index.html.twig', [
            'demo_form' => $this->demoForm()->createView(),
        ]);
    }

    /**
     * Demo form for the form theme: help, an error on one field, a form-level error, every widget type.
     *
     * @return FormInterface the unsubmitted form with one field error and one form error
     */
    private function demoForm(): FormInterface
    {
        $data = [
            'name' => $this->translator->trans('form.name.value', [], 'styleguide'),
            'email' => $this->translator->trans('form.email.value', [], 'styleguide'),
            'weekday' => 'tue',
            'duration' => 60,
            'reminder' => true,
        ];

        $form = $this->createFormBuilder($data, ['translation_domain' => 'styleguide', 'csrf_protection' => false])
            ->setAction('#sg-form')
            ->setMethod('GET')
            ->add('name', TextType::class, [
                'label' => 'form.name.label',
                'help' => 'form.name.help',
                'attr' => ['placeholder' => 'form.name.placeholder'],
            ])
            ->add('email', EmailType::class, ['label' => 'form.email.label'])
            ->add('password', PasswordType::class, ['label' => 'form.password.label'])
            ->add('course', ChoiceType::class, [
                'label' => 'form.course.label',
                'placeholder' => 'form.course.placeholder',
                'choices' => [
                    'form.course.salsa' => 'salsa',
                    'form.course.bachata' => 'bachata',
                    'form.course.kizomba' => 'kizomba',
                ],
            ])
            ->add('weekday', ChoiceType::class, [
                'label' => 'form.weekday.label',
                'expanded' => true,
                'attr' => ['class' => 'segmented'],
                'choices' => [
                    'form.weekday.mon' => 'mon',
                    'form.weekday.tue' => 'tue',
                    'form.weekday.wed' => 'wed',
                    'form.weekday.thu' => 'thu',
                    'form.weekday.fri' => 'fri',
                    'form.weekday.sat' => 'sat',
                    'form.weekday.sun' => 'sun',
                ],
            ])
            ->add('duration', ChoiceType::class, [
                'label' => 'form.duration.label',
                'expanded' => true,
                'attr' => ['class' => 'segmented'],
                'choice_translation_domain' => false,
                'choices' => ['45' => 45, '60' => 60, '75' => 75, '90' => 90],
            ])
            ->add('level', ChoiceType::class, [
                'label' => 'form.level.label',
                'expanded' => true,
                'required' => false,
                'placeholder' => false,
                'choices' => [
                    'form.level.beginner' => 'beginner',
                    'form.level.improver' => 'improver',
                ],
            ])
            ->add('notes', TextareaType::class, ['label' => 'form.notes.label', 'required' => false])
            ->add('reminder', CheckboxType::class, ['label' => 'form.reminder.label', 'required' => false])
            ->add('submit', SubmitType::class, ['label' => 'form.submit'])
            ->getForm();

        $form->addError(new FormError($this->translator->trans('form.summary', [], 'styleguide')));
        $form->get('email')->addError(new FormError($this->translator->trans('form.email.error', [], 'styleguide')));

        return $form;
    }
}
