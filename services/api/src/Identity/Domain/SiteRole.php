<?php

declare(strict_types=1);

namespace Analytics\Identity\Domain;

enum SiteRole: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';
}
