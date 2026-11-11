<?php

declare(strict_types=1);

namespace App\Students\Form;

use App\Students\Entity\Student;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the student form submits (STU-1). Lengths follow the `student` columns; email and notes
 * are optional and for the teacher only (PRD §4.2). Messages are keys in the `validators`
 * domain, prefixed with the module (`students.`, D-038).
 */
final class StudentData
{
    /** The column is TEXT; the form caps it (D3). */
    public const NOTES_MAX = 2000;

    #[Assert\NotBlank(message: 'students.student.first_name.blank')]
    #[Assert\Length(max: Student::NAME_MAX, maxMessage: 'students.student.first_name.too_long')]
    public ?string $firstName = null;

    #[Assert\NotBlank(message: 'students.student.last_name.blank')]
    #[Assert\Length(max: Student::NAME_MAX, maxMessage: 'students.student.last_name.too_long')]
    public ?string $lastName = null;

    #[Assert\Email(message: 'students.student.email.invalid', mode: Assert\Email::VALIDATION_MODE_HTML5)]
    #[Assert\Length(max: Student::EMAIL_MAX, maxMessage: 'students.student.email.too_long')]
    public ?string $email = null;

    #[Assert\Length(max: self::NOTES_MAX, maxMessage: 'students.student.notes.too_long')]
    public ?string $notes = null;

    public static function fromStudent(Student $student): self
    {
        $data = new self();
        $data->firstName = $student->getFirstName();
        $data->lastName = $student->getLastName();
        $data->email = $student->getEmail();
        $data->notes = $student->getNotes();

        return $data;
    }
}
