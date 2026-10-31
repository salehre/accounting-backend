<?php

namespace App\Support;

/**
 * The complete list of permissions in the application.
 * Routes and roles may only use names from this list (a test enforces it),
 * so a typo can never silently lock people out or grant the wrong access.
 */
final class Permissions
{
    public const ALL = [
        'accounts.read',
        'accounts.manage',
        'entries.read',
        'entries.write',
        'entries.post',
        'entries.reverse',
        'reports.read',
        'users.manage',
    ];
}
