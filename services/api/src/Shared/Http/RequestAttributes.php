<?php

declare(strict_types=1);

namespace Analytics\Shared\Http;

final class RequestAttributes
{
    public const string REQUEST_ID = 'request_id';
    public const string IP_PREFIX = 'ip_prefix';
    public const string RAW_BODY = 'raw_body';
    public const string AUTH_SESSION = 'auth_session';
    public const string USER = 'user';
    public const string SITE = 'site';
    public const string SITE_ROLE = 'site_role';
    public const string API_KEY = 'api_key';
}
