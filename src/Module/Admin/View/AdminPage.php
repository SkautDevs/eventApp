<?php

declare(strict_types=1);

namespace App\Module\Admin\View;

use App\Push\Message;

/**
 * Everything templates/admin-notify.twig is rendered with, one property per template
 * variable: the CSRF token and the form's one-time nonce, a notice, the last send's result
 * (null when none was flashed), the form's errors and values, the programme picker by day
 * and whether the programmes could not be read, and one page of the message log with its
 * number and whether an older page exists. AdminPageRenderer renders get_object_vars() of it.
 */
final readonly class AdminPage
{
    /**
     * @param list<string> $errors
     * @param list<ProgrammeGroup> $groups
     * @param list<Message> $log
     */
    public function __construct(
        public string $csrf,
        public string $nonce,
        public ?string $notice,
        public ?NotifyResult $result,
        public array $errors,
        public FormValues $values,
        public array $groups,
        public bool $programmesUnavailable,
        public array $log,
        public int $page,
        public bool $hasOlder,
    ) {}
}
