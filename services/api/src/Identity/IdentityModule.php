<?php

declare(strict_types=1);

namespace Analytics\Identity;

use Analytics\Identity\Application\IdentityMails;
use Analytics\Identity\Application\InvitationService;
use Analytics\Identity\Application\PasswordHasher;
use Analytics\Identity\Application\Permission;
use Analytics\Identity\Application\SessionManager;
use Analytics\Identity\Application\TotpService;
use Analytics\Identity\Http\AuthController;
use Analytics\Identity\Http\InvitationsController;
use Analytics\Identity\Http\UsersController;
use Analytics\Kernel\Module;
use Analytics\Kernel\Routing\SecuredRoutes;
use Analytics\Kernel\Settings;
use Slim\Interfaces\RouteCollectorProxyInterface;

use function DI\autowire;
use function DI\factory;

final class IdentityModule extends Module
{
    public function definitions(Settings $settings): array
    {
        return [
            PasswordHasher::class => factory(static fn(Settings $s): PasswordHasher => new PasswordHasher($s->isTest())),
            SessionManager::class => autowire()->constructorParameter('secureCookie', $settings->usesHttps()),
            TotpService::class => autowire()->constructorParameter('issuer', 'Analytics (' . $settings->appHost() . ')'),
            InvitationService::class => autowire()->constructorParameter('appUrl', $settings->appUrl),
            IdentityMails::class => autowire()->constructorParameter('appUrl', $settings->appUrl),
        ];
    }

    /** @param RouteCollectorProxyInterface<\Psr\Container\ContainerInterface|null> $group */

    public function publicApiRoutes(RouteCollectorProxyInterface $group): void
    {
        $group->get('/auth/config', [AuthController::class, 'config']);
        $group->post('/auth/login', [AuthController::class, 'login']);
        $group->post('/auth/mfa', [AuthController::class, 'mfa']);
        $group->post('/auth/password/forgot', [AuthController::class, 'forgotPassword']);
        $group->post('/auth/password/reset', [AuthController::class, 'resetPassword']);
        $group->post('/auth/email/confirm', [AuthController::class, 'confirmEmailChange']);
        $group->get('/invitations/{token:[A-Za-z0-9_-]{43}}', [InvitationsController::class, 'showPublic']);
        $group->post('/invitations/{token:[A-Za-z0-9_-]{43}}/accept', [InvitationsController::class, 'accept']);
    }

    public function apiRoutes(SecuredRoutes $routes): void
    {
        $auth = Permission::AUTHENTICATED;
        $routes->post('/auth/logout', [AuthController::class, 'logout'], $auth);
        $routes->get('/auth/me', [AuthController::class, 'me'], $auth);
        $routes->patch('/auth/me', [AuthController::class, 'updateProfile'], $auth);
        $routes->post('/auth/password', [AuthController::class, 'changePassword'], $auth);
        $routes->post('/auth/email', [AuthController::class, 'requestEmailChange'], $auth);
        $routes->delete('/auth/email', [AuthController::class, 'cancelEmailChange'], $auth);
        $routes->post('/auth/totp/setup', [AuthController::class, 'totpSetup'], $auth);
        $routes->post('/auth/totp/confirm', [AuthController::class, 'totpConfirm'], $auth);
        $routes->delete('/auth/totp', [AuthController::class, 'totpDisable'], $auth);
        $routes->post('/auth/totp/recovery-codes', [AuthController::class, 'recoveryCodes'], $auth);
        $routes->get('/auth/sessions', [AuthController::class, 'sessions'], $auth);
        $routes->delete('/auth/sessions', [AuthController::class, 'revokeOtherSessions'], $auth);
        $routes->delete('/auth/sessions/{sessionId:[0-9a-f]{16}}', [AuthController::class, 'revokeSession'], $auth);

        $admin = Permission::ADMIN;
        $routes->get('/users', [UsersController::class, 'list'], $admin);
        $routes->get('/users/{userId:[0-9]+}', [UsersController::class, 'show'], $admin);
        $routes->patch('/users/{userId:[0-9]+}', [UsersController::class, 'update'], $admin);
        $routes->delete('/users/{userId:[0-9]+}/totp', [UsersController::class, 'resetTotp'], $admin);
        $routes->get('/invitations', [InvitationsController::class, 'list'], $admin);
        $routes->post('/invitations', [InvitationsController::class, 'create'], $admin);
        $routes->delete('/invitations/{invitationId:[0-9]+}', [InvitationsController::class, 'revoke'], $admin);
        $routes->get('/sites/{siteId:[0-9]+}/invitations', [InvitationsController::class, 'listForSite'], Permission::SITE_MANAGE);
        $routes->post('/sites/{siteId:[0-9]+}/invitations', [InvitationsController::class, 'createForSite'], Permission::SITE_MANAGE);
        $routes->delete('/sites/{siteId:[0-9]+}/invitations/{invitationId:[0-9]+}', [InvitationsController::class, 'revokeForSite'], Permission::SITE_MANAGE);
    }

    public function commands(): array
    {
        return [
            Console\UserCreateAdminCommand::class,
            Console\UserListCommand::class,
            Console\UserDisableCommand::class,
            Console\UserSetPasswordCommand::class,
            Console\UserResetTwoFactorCommand::class,
            Console\InvitationCreateCommand::class,
            Console\SecretsRotateKeyCommand::class,
        ];
    }

    public function entityPaths(): array
    {
        return [__DIR__ . '/Domain'];
    }
}
