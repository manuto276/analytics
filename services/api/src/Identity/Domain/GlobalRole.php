<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

enum GlobalRole: string
{
    /** Manages users, invitations, all sites. */
    case Admin = 'admin';
    /** Accesses only the sites granted through user_site_roles. */
    case Member = 'member';
}
