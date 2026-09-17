<?php

declare(strict_types=1);

namespace Analytics\Identity\Application;

final class Permission
{
    /** Any signed-in user with a fully authenticated session. */
    public const string AUTHENTICATED = 'authenticated';
    /** Global admin only. */
    public const string ADMIN = 'admin';
    /** Site viewer, site admin, or global admin (route must have {siteId}). */
    public const string SITE_VIEW = 'site:view';
    /** Site admin or global admin (route must have {siteId}). */
    public const string SITE_MANAGE = 'site:manage';

    public const array ALL = [self::AUTHENTICATED, self::ADMIN, self::SITE_VIEW, self::SITE_MANAGE];
}
