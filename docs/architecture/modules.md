# Backend modules

The backend lives in `services/api/src` under the namespace `Analytics\`. It is split into modules,
each with the same internal layering. Modules are registered in `Analytics\Kernel\Modules::all()`, in
this order:

```
Shared · Audit · Sites · Identity · Consent · Tracking · Conversions · Reporting · Retention · Health · Kernel
```

A module extends `Analytics\Kernel\Module` and may override:

| Hook | Purpose |
|---|---|
| `definitions(Settings)` | PHP-DI container definitions |
| `entityPaths()` | directories scanned for Doctrine ORM attribute mapping |
| `trackingRoutes(group)` | routes under `/t` |
| `publicApiRoutes(group)` | routes under `/api/v1` that need no session |
| `apiRoutes(SecuredRoutes)` | session routes under `/api/v1`; each **must** declare a permission |
| `serverRoutes(SecuredRoutes)` | routes under `/api/v1/server`; each **must** declare an API-key scope |
| `rootRoutes(app)` | top-level routes (SPA fallback, `robots.txt`) |
| `commands()` | Symfony Console command classes |

`SecuredRoutes` records the declared requirement in `RouteRegistry`. `AccessMiddleware` (sessions) and
`ApiKeyAuthMiddleware` (server API) read it back and throw a `LogicException` if a route declares
nothing — a new route without a permission fails the build rather than being open by default.

## Module map

| Module | Main classes |
|---|---|
| **Kernel** | `Kernel`, `Settings`, `ContainerFactory`, `AppFactory`, `ConsoleApplicationFactory`, `BuildInfo`, `Pipeline`, `Modules`, `Routing\RouteRegistry`, `Routing\SecuredRoutes`, `ApiGroupMiddleware`, `ServerApiGroupMiddleware`, `Http\RequestContext`, `Http\SpaFallbackAction`; commands `app:preflight`, `cache:warmup`, `cache:clear`, `secrets:generate`, `orm:validate-schema` |
| **Shared** | `Http\ApiProblem`, `Http\JsonResponder`, `Http\ProblemDetailsErrorHandler`, `Http\RequestAttributes`, the six middleware in `Http\Middleware`; `Crypto\SecretBox` (XChaCha20-Poly1305 key ring), `Crypto\KeyDerivation`, `Crypto\TokenGenerator`, `Crypto\TokenHasher`, `Crypto\Base64Url`; `Net\ClientIpResolver`, `Net\IpTruncator`, `Net\IpPrefix`; `RateLimit\RateLimiter`; `Jobs\JobRunner`; `Log\IpScrubbingProcessor`; `Doctrine\ConnectionFactory`, `EntityManagerFactory`, `Partitioning`, `SchemaAssets`; `Validation\Input`; `Mail\Mailer` (+ `SymfonyMailer`, `RecordingMailer`); `Types` |
| **Sites** | `Domain\Site`, `SiteDomain`, `SiteSnapshot`, `VisitorHashMode`, `DntMode`, `DomainMatcher`; `Application\SiteService`, `SiteRepository`, `SnippetRenderer`; `Http\SitesController`; commands `site:create`, `site:list`, `site:show`, `site:domain:add`, `site:domain:remove` |
| **Identity** | `Domain\User`, `GlobalRole`, `SiteRole`, `UserSiteRole`, `Invitation`, `AuthSession`, `SessionState`, `TotpCredential`, `RecoveryCode`, `PasswordReset`; `Application\LoginService`, `SessionManager`, `PasswordHasher`, `TotpService`, `InvitationService`, `PasswordResetService`, `UserService`, `Authorizer`, `Permission`; `Http\SessionMiddleware`, `CsrfMiddleware`, `AccessMiddleware`, `AuthController`, `UsersController`, `InvitationsController`; commands `user:create-admin`, `user:list`, `user:disable`, `user:set-password`, `user:reset-2fa`, `invitation:create`, `secrets:rotate-key` |
| **Consent** | `Domain\ConsentConfig`; `Application\ConsentService`, `ContrastChecker`; `Http\ConsentController` |
| **Tracking** | `Http\TrackingController`; `Application\CollectService`, `CollectContext`, `PayloadParser`/`ParsedPayload`/`ParsedEvent`, `EventDraft`, `IngestBatchHandler`, `VisitorHasher`, `DailySaltProvider`, `DirtyDayMarker`, `ForgetService`, `ScriptBundleBuilder`, `EventSink`, `GeoLocator`, `Seeder`, `Enrichment\*` (`BotFilter`, `UserAgentClassifier`, `UrlSanitizer`, `PiiScrubber`, `ReferrerClassifier`, `ChannelClassifier`, `TrafficSource`, `UserAgentInfo`); `Domain\EventType`, `TrackingLevel`, `Channel`, `ConsentStatKind`; `Infrastructure\SyncEventSink`, `RedisQueueEventSink`, `DbalDailySaltProvider`, `RedisDailySaltProvider`, `MaxMindGeoLocator`; commands `salt:rotate`, `queue:work`, `geo:update`, `geo:lookup` |
| **Conversions** | `Domain\ApiKey`, `Goal`, `GoalType`, `Funnel`, `FunnelStep`, `CampaignCost`; `Application\ApiKeyService`, `ConversionIngestHandler`, `AttributionResolver`, `CustomerRefHasher`, `CostImporter`; `Http\ApiKeyAuthMiddleware`, `ServerController`, `ConversionsAdminController`; commands `api-key:create`, `api-key:revoke`, `conversions:reattribute` |
| **Reporting** | `Domain\ReportQuery`, `DateRange`, `Interval`, `Comparison`, `Filter`, `FilterOperator`; `Application\ReportService`, `ReportQueryFactory`, `QueryPlanner`, `ReportCache`, `SqlFilters`, `DailyMetrics`, `CsvExporter`, `Cursor`, `JobsStatus`, `Reports\*` (`TableReports`, `TimeseriesReport`, `RealtimeReport`, `GoalsReport`, `FunnelReport`, `AttributionReport`, `ConsentReport`, `CohortsReport`), `Rollup\RollupRunner`, `Rollup\RollupBuilder`, `Rollup\RawSelects`; `Http\ReportsController`; commands `rollup:run`, `rollup:rebuild`, `jobs:status`, `dev:seed` |
| **Retention** | `Application\PartitionManager`, `RetentionPurger`; commands `partitions:maintain`, `retention:purge` |
| **Audit** | `Domain\AuditLogEntry`; `Application\AuditLogger`, `Actor`; `Http\AuditController` (`/admin/audit-log`, `/admin/jobs`) |
| **Health** | `HealthChecker`, `HealthController` (`GET /api/v1/health` public, `GET /api/v1/admin/health` admin), `HealthCheckCommand` |

## Layering

Inside a module, code lives in `Domain/`, `Application/`, `Infrastructure/`, `Http/` and `Console/`.
`services/api/deptrac.yaml` turns those directories into layers and enforces:

| Layer | May depend on |
|---|---|
| `Domain` | `Shared` |
| `Application` | `Domain`, `Shared`, `Kernel` |
| `Infrastructure` | `Application`, `Domain`, `Shared`, `Kernel` |
| `Http` | `Application`, `Domain`, `Shared`, `Kernel` |
| `Console` | `Application`, `Domain`, `Shared`, `Kernel`, `Infrastructure` |
| `ModuleRoot` (the `*Module` classes) | everything |
| `Health` | `Shared`, `Kernel`, `Application` |
| `Shared` | `Kernel`, `Domain` |
| `Kernel` | everything |

Consequences worth knowing:

- **Domain classes are plain PHP.** They hold Doctrine attributes and enums, no database access.
- **`Http` never touches `Infrastructure`.** A controller asks for an interface (`EventSink`,
  `DailySaltProvider`, `GeoLocator`) and the container decides which implementation to bind, based on
  `INGEST_MODE` and `REDIS_DSN`.
- **Deptrac does not separate modules from each other**, only layers. Cross-module calls are
  restricted by convention: a module talks to another one through its `Application` services
  (`SiteRepository`, `ConsentService`, `AuditLogger`, `RateLimiter`) and never through its
  `Infrastructure`. Reporting reads other modules' tables directly through DBAL, by design: it owns
  the read models (`RawSelects`, `TableReports`) and never goes through the ORM for analytic data.

Run it with `make deptrac` (`vendor/bin/deptrac analyse`).

## ORM vs DBAL

`Analytics\Shared\Doctrine\SchemaAssets::DBAL_TABLES` lists the tables the ORM must ignore. The
`EntityManagerFactory` installs it as the schema-assets filter, so `migrations:diff` and
`orm:validate-schema` never try to manage them. Configuration and identity tables are ORM entities;
hot and analytic tables are raw SQL in `migrations/Version20260917000002.php` and are read and written
with DBAL only. See [data-model.md](data-model.md).

## Static analysis and style

| Tool | Configuration | Make target |
|---|---|---|
| PHPStan (max level, doctrine/phpunit/strict/deprecation extensions) | `phpstan.neon.dist`, `phpstan-tests.neon.dist` | `make stan` |
| Deptrac | `deptrac.yaml` | `make deptrac` |
| PHP-CS-Fixer | `.php-cs-fixer.dist.php` | `make cs`, `make cs-fix` |
| Rector | `rector.php` | `make rector` |

See [../development/conventions.md](../development/conventions.md).
