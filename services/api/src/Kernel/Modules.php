<?php

declare(strict_types=1);

namespace Analytics\Kernel;

final class Modules
{
    /** @var list<Module>|null */
    private static ?array $modules = null;

    /** @return list<Module> */
    public static function all(): array
    {
        return self::$modules ??= [
            new \Analytics\Shared\SharedModule(),
            new \Analytics\Audit\AuditModule(),
            new \Analytics\Sites\SitesModule(),
            new \Analytics\Identity\IdentityModule(),
            new \Analytics\Consent\ConsentModule(),
            new \Analytics\Tracking\TrackingModule(),
            new \Analytics\Conversions\ConversionsModule(),
            new \Analytics\Reporting\ReportingModule(),
            new \Analytics\Retention\RetentionModule(),
            new \Analytics\Health\HealthModule(),
            new \Analytics\Kernel\KernelModule(),
        ];
    }
}
