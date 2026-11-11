<?php

declare(strict_types=1);

namespace App\CheckIn\Service;

/**
 * Why a public check-in was refused. The reason is for code and tests only: #14 shows
 * one public message for all of them, so a visitor learns nothing about the token (S8).
 */
enum CheckInRefusal: string
{
    case Malformed = 'malformed';
    case Unknown = 'unknown';
    case Closed = 'closed';
    case Expired = 'expired';
    case LessonCancelled = 'lesson_cancelled';
    case CourseArchived = 'course_archived';
    case NotEnrolled = 'not_enrolled';
}
