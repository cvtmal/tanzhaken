<?php

declare(strict_types=1);

namespace App\Account\Form;

use App\Account\Entity\Teacher;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What the profile card of /account submits: display name and time zone (ACC-4).
 * Messages are keys in the `validators` domain, prefixed with the module (`account.`).
 */
final class ProfileData
{
    #[Assert\NotBlank(message: 'account.settings.name.blank')]
    #[Assert\Length(max: Teacher::NAME_MAX, maxMessage: 'account.settings.name.too_long')]
    public ?string $name = null;

    #[Assert\NotBlank(message: 'account.settings.timezone.invalid')]
    #[Assert\Timezone(message: 'account.settings.timezone.invalid')]
    public ?string $timezone = null;

    public static function fromTeacher(Teacher $teacher): self
    {
        $data = new self();
        $data->name = $teacher->getName();
        $data->timezone = $teacher->getTimezone();

        return $data;
    }
}
