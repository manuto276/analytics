<?php

declare(strict_types=1);

namespace Analytics\Tracking\Domain;

enum Channel: string
{
    case Direct = 'direct';
    case OrganicSearch = 'organic_search';
    case PaidSearch = 'paid_search';
    case OrganicSocial = 'organic_social';
    case PaidSocial = 'paid_social';
    case Email = 'email';
    case Referral = 'referral';
    case Campaign = 'campaign';
    case Internal = 'internal';

    public function isExternal(): bool
    {
        return $this !== self::Direct && $this !== self::Internal;
    }
}
