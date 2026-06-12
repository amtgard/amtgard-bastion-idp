<?php

declare(strict_types=1);

namespace Amtgard\IdP\Tests\Controllers;

require_once __DIR__ . '/AuthControllerTest.php';

use Amtgard\ActiveRecordOrm\EntityManager;
use Amtgard\IdP\Controllers\Client\AppleAuthController;
use Amtgard\IdP\Controllers\Client\DiscordAuthController;
use Amtgard\IdP\Controllers\Client\FacebookAuthController;
use Amtgard\IdP\Controllers\Client\GoogleAuthController;
use Amtgard\IdP\Models\AmtgardIdpJwt;
use Amtgard\IdP\Persistence\Client\Repositories\UserLoginRepository;
use Amtgard\IdP\Persistence\Client\Repositories\UserRepository;
use Amtgard\IdP\Tests\Support\ScriptAlertResponseAssert;
use Amtgard\IdP\Utility\Security\OAuth2StateManager;
use League\OAuth2\Client\Provider\Apple;
use League\OAuth2\Client\Provider\Facebook;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Slim\Interfaces\RouteParserInterface;
use Slim\Routing\RouteContext;
use Slim\Routing\RoutingResults;
use Wohali\OAuth2\Client\Provider\Discord;

class SocialAuthControllersTest extends TestCase
{
    private UserRepository $users;
    private UserLoginRepository $logins;
    private AmtgardIdpJwt $amtgardIdpJwt;
    private ResponseInterface $response;
    private StreamInterface $stream;
    private ServerRequestInterface $request;
    private RouteParserInterface $routeParser;

    protected function setUp(): void
    {
        @session_start();
        $_SESSION = [];

        $this->users = $this->createMock(UserRepository::class);
        $this->logins = $this->createMock(UserLoginRepository::class);
        $this->amtgardIdpJwt = $this->createMock(AmtgardIdpJwt::class);
        $this->response = $this->createMock(ResponseInterface::class);
        $this->stream = $this->createMock(StreamInterface::class);
        $this->request = $this->createMock(ServerRequestInterface::class);
        $this->routeParser = $this->createMock(RouteParserInterface::class);

        $this->response->method('getBody')->willReturn($this->stream);
        $this->response->method('withHeader')->willReturnSelf();
        $this->response->method('withStatus')->willReturnSelf();

        $routingResults = $this->createMock(RoutingResults::class);
        $this->request->method('getAttribute')->willReturnCallback(function (string $name) {
            if ($name === RouteContext::ROUTE_PARSER) {
                return $this->routeParser;
            }
            if ($name === RouteContext::ROUTING_RESULTS) {
                return $this->createMock(RoutingResults::class);
            }
            return null;
        });
    }

    public function testGoogleRedirectStoresStateAndRedirects(): void
    {
        $provider = $this->createMock(Google::class);
        $provider->method('getAuthorizationUrl')->willReturn('https://accounts.google.com/o/oauth2/auth');
        $provider->method('getState')->willReturn('state-123');

        $this->request->method('getQueryParams')->willReturn(['redirect' => '/profile']);

        $controller = new GoogleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', 'https://accounts.google.com/o/oauth2/auth')
            ->willReturnSelf();
        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();

        $controller->redirectToGoogle($this->request, $this->response);

        $this->assertSame('state-123', $_SESSION['oauth2state'] ?? null);
    }

    public function testGoogleCallbackReturnsValidationHtmlWhenStateInvalid(): void
    {
        $provider = $this->createMock(Google::class);
        $this->request->method('getQueryParams')->willReturn(['code' => 'abc', 'state' => 'bad']);

        $controller = new GoogleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->stream->expects($this->once())->method('write')->with($this->callback(function (string $html): bool {
            ScriptAlertResponseAssert::assertRedirectWithError($html, 'Invalid state parameter', '/auth/login');
            return true;
        }));
        $controller->handleGoogleCallback($this->request, $this->response);
    }

    public function testGoogleCallbackFinalizesAuthorizationForExistingUser(): void
    {
        OAuth2StateManager::store('state-ok');
        $provider = $this->createMock(Google::class);
        $token = new AccessToken(['access_token' => 'at', 'refresh_token' => 'rt']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return [
                    'email' => 'user@example.com',
                    'sub' => 'google-sub',
                    'given_name' => 'Test',
                    'family_name' => 'User',
                ];
            }
        };

        $provider->method('getAccessToken')->willReturn($token);
        $provider->method('getResourceOwner')->willReturn($resourceOwner);

        $user = new TestUserEntity('uuid-1', 'user@example.com', 'Test User');
        $login = new TestUserLoginEntity($user, 'hash', 'avatar', 7);

        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByProviderId')->willReturn(null);
        $this->logins->method('createLoginFromGoogleData')->willReturn($login);
        $this->amtgardIdpJwt->method('buildAuthorizationJwt')->willReturn('jwt-token');
        $this->routeParser->method('urlFor')->willReturn('/resources/profile');

        $this->request->method('getQueryParams')->willReturn(['code' => 'abc', 'state' => 'state-ok']);

        $controller = new GoogleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();
        $controller->handleGoogleCallback($this->request, $this->response);
        $this->assertSame('uuid-1', $_SESSION['user_id']);
    }

    public function testFacebookRedirectStoresState(): void
    {
        $provider = $this->createMock(Facebook::class);
        $provider->method('getAuthorizationUrl')->willReturn('https://facebook.com/dialog/oauth');
        $provider->method('getState')->willReturn('fb-state');

        $controller = new FacebookAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();
        $controller->redirectToFacebook($this->request, $this->response);
        $this->assertSame('fb-state', $_SESSION['oauth2state'] ?? null);
    }

    public function testFacebookCallbackHandlesProviderError(): void
    {
        $provider = $this->createMock(Facebook::class);
        $this->request->method('getQueryParams')->willReturn(['error' => 'access_denied']);

        $controller = new FacebookAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->stream->expects($this->once())->method('write')->with($this->stringContains('Facebook'));
        $controller->handleFacebookCallback($this->request, $this->response);
    }

    public function testFacebookCallbackFinalizesAuthorizationForNewLogin(): void
    {
        OAuth2StateManager::store('fb-state');
        $provider = $this->createMock(Facebook::class);
        $shortToken = new AccessToken(['access_token' => 'short']);
        $longToken = new AccessToken(['access_token' => 'long']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return [
                    'id' => 'facebook-id',
                    'email' => 'fb@example.com',
                    'first_name' => 'Face',
                    'last_name' => 'Book',
                    'picture_url' => 'https://facebook.example/avatar.jpg',
                ];
            }
        };
        $user = new TestUserEntity('uuid-fb', 'fb@example.com', 'Face Book');
        $login = new TestUserLoginEntity($user, 'hash', 'https://facebook.example/avatar.jpg', 8);

        $provider->expects($this->once())->method('getAccessToken')->with('authorization_code', ['code' => 'abc'])->willReturn($shortToken);
        $provider->expects($this->once())->method('getLongLivedAccessToken')->with('short')->willReturn($longToken);
        $provider->expects($this->once())->method('getResourceOwner')->with($longToken)->willReturn($resourceOwner);
        $this->users->method('getUserByEmail')->with('fb@example.com')->willReturn(null);
        $this->users->expects($this->once())->method('createUserFromFacebookData')->willReturn($user);
        $this->logins->expects($this->once())->method('getLoginByProviderId')->with('facebook-id')->willReturn(null);
        $this->logins->expects($this->once())->method('createLoginFromFacebookData')->with($user, $resourceOwner->toArray(), $longToken)->willReturn($login);
        $this->amtgardIdpJwt->method('buildAuthorizationJwt')->with($user)->willReturn('jwt-token');
        $this->routeParser->method('urlFor')->with('resources.profile')->willReturn('/resources/profile');
        $this->request->method('getQueryParams')->willReturn(['code' => 'abc', 'state' => 'fb-state']);

        $controller = new FacebookAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();
        $controller->handleFacebookCallback($this->request, $this->response);

        $this->assertSame('uuid-fb', $_SESSION['user_id']);
        $this->assertSame('fb@example.com', $_SESSION['user_email']);
    }

    public function testDiscordRedirectStoresStateAndRedirect(): void
    {
        $provider = $this->createMock(Discord::class);
        $provider->method('getAuthorizationUrl')->willReturn('https://discord.com/oauth2/authorize');
        $provider->method('getState')->willReturn('dc-state');
        $this->request->method('getQueryParams')->willReturn(['redirect' => '/home']);

        $controller = new DiscordAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();
        $controller->redirectToDiscord($this->request, $this->response);
        $this->assertSame('dc-state', $_SESSION['oauth2state'] ?? null);
    }

    public function testDiscordCallbackRejectsMissingEmail(): void
    {
        OAuth2StateManager::store('dc-state');
        $provider = $this->createMock(Discord::class);
        $token = new AccessToken(['access_token' => 'at']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return ['id' => 'discord-id', 'username' => 'player'];
            }
        };

        $provider->method('getAccessToken')->willReturn($token);
        $provider->method('getResourceOwner')->willReturn($resourceOwner);
        $this->request->method('getQueryParams')->willReturn(['code' => 'abc', 'state' => 'dc-state']);

        $controller = new DiscordAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->stream->expects($this->once())->method('write')->with($this->callback(function (string $html): bool {
            ScriptAlertResponseAssert::assertRedirectWithError(
                $html,
                'Email permission denied or not provided by Discord.',
                '/auth/login?policy'
            );
            return true;
        }));
        $controller->handleDiscordCallback($this->request, $this->response);
    }

    public function testDiscordCallbackFinalizesAuthorizationForNewLogin(): void
    {
        OAuth2StateManager::store('dc-state');
        $provider = $this->createMock(Discord::class);
        $token = new AccessToken(['access_token' => 'at', 'refresh_token' => 'rt']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return [
                    'id' => 'discord-id',
                    'username' => 'discorduser',
                    'email' => 'discord@example.com',
                    'avatar' => 'avatar-hash',
                ];
            }
        };
        $user = new TestUserEntity('uuid-dc', 'discord@example.com', 'discorduser');
        $login = new TestUserLoginEntity($user, 'hash', 'https://cdn.discordapp.com/avatar.png', 9);

        $provider->expects($this->once())->method('getAccessToken')->with('authorization_code', ['code' => 'abc'])->willReturn($token);
        $provider->expects($this->once())->method('getResourceOwner')->with($token)->willReturn($resourceOwner);
        $this->users->method('getUserByEmail')->with('discord@example.com')->willReturn(null);
        $this->users->expects($this->once())->method('createUserFromGoogleData')->with($this->callback(function (array $data): bool {
            return $data['email'] === 'discord@example.com'
                && $data['given_name'] === 'discorduser'
                && $data['picture'] === 'https://cdn.discordapp.com/avatars/discord-id/avatar-hash.png';
        }))->willReturn($user);
        $this->logins->expects($this->once())->method('getLoginByProviderId')->with('discord-id')->willReturn(null);
        $this->logins->expects($this->once())->method('createLoginFromDiscordData')->with($user, $resourceOwner->toArray(), $token)->willReturn($login);
        $this->amtgardIdpJwt->method('buildAuthorizationJwt')->with($user)->willReturn('jwt-token');
        $this->routeParser->method('urlFor')->with('resources.profile')->willReturn('/resources/profile');
        $this->request->method('getQueryParams')->willReturn(['code' => 'abc', 'state' => 'dc-state']);

        $controller = new DiscordAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();
        $controller->handleDiscordCallback($this->request, $this->response);

        $this->assertSame('uuid-dc', $_SESSION['user_id']);
        $this->assertSame('discord@example.com', $_SESSION['user_email']);
    }

    public function testGoogleCallbackCreatesUserWhenEmailUnknown(): void
    {
        OAuth2StateManager::store('state-new');
        $provider = $this->createMock(Google::class);
        $token = new AccessToken(['access_token' => 'at', 'refresh_token' => 'rt']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return [
                    'email' => 'newgoogle@example.com',
                    'sub' => 'google-new',
                    'given_name' => 'New',
                    'family_name' => 'Googler',
                    'picture' => 'https://example.com/p.jpg',
                ];
            }
        };
        $user = new TestUserEntity('uuid-new', 'newgoogle@example.com', 'New Googler');
        $login = new TestUserLoginEntity($user, 'hash', 'https://example.com/p.jpg', 12);

        $provider->method('getAccessToken')->willReturn($token);
        $provider->method('getResourceOwner')->willReturn($resourceOwner);
        $this->users->method('getUserByEmail')->willReturn(null);
        $this->users->expects($this->once())->method('createUserFromGoogleData')->willReturn($user);
        $this->logins->method('getLoginByProviderId')->willReturn(null);
        $this->logins->expects($this->once())->method('createLoginFromGoogleData')->willReturn($login);
        $this->amtgardIdpJwt->method('buildAuthorizationJwt')->willReturn('jwt-token');
        $this->routeParser->method('urlFor')->willReturn('/resources/profile');
        $this->request->method('getQueryParams')->willReturn(['code' => 'abc', 'state' => 'state-new']);

        $controller = new GoogleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $controller->handleGoogleCallback($this->request, $this->response);
        $this->assertSame('uuid-new', $_SESSION['user_id']);
    }

    public function testGoogleCallbackUsesStoredRedirectWhenPresent(): void
    {
        OAuth2StateManager::store('state-redirect');
        $_SESSION['redirect'] = '/app/callback';
        $provider = $this->createMock(Google::class);
        $token = new AccessToken(['access_token' => 'at', 'refresh_token' => 'rt']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return [
                    'email' => 'user@example.com',
                    'sub' => 'google-sub',
                    'given_name' => 'Test',
                    'family_name' => 'User',
                ];
            }
        };
        $user = new TestUserEntity('uuid-1', 'user@example.com', 'Test User');
        $login = new TestUserLoginEntity($user, 'hash', 'avatar', 7);

        $provider->method('getAccessToken')->willReturn($token);
        $provider->method('getResourceOwner')->willReturn($resourceOwner);
        $this->users->method('getUserByEmail')->willReturn($user);
        $this->logins->method('getLoginByProviderId')->willReturn($login);
        $this->logins->expects($this->once())->method('updateLoginTokens')->willReturn($login);
        $this->amtgardIdpJwt->method('buildAuthorizationJwt')->willReturn('signed-jwt');
        $this->request->method('getQueryParams')->willReturn(['code' => 'abc', 'state' => 'state-redirect']);

        $controller = new GoogleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())
            ->method('withHeader')
            ->with('Location', '/app/callback?jwt=signed-jwt')
            ->willReturnSelf();

        $controller->handleGoogleCallback($this->request, $this->response);
    }

    public function testFacebookCallbackRejectsInvalidState(): void
    {
        $provider = $this->createMock(Facebook::class);
        $this->request->method('getQueryParams')->willReturn(['code' => 'abc', 'state' => 'bad-state']);

        $controller = new FacebookAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->stream->expects($this->once())->method('write')->with($this->callback(function (string $html): bool {
            ScriptAlertResponseAssert::assertRedirectWithError($html, 'Invalid state parameter', '/auth/login');
            return true;
        }));
        $controller->handleFacebookCallback($this->request, $this->response);
    }

    public function testAppleRedirectStoresStateAndRedirect(): void
    {
        $provider = $this->createMock(Apple::class);
        $provider->method('getAuthorizationUrl')->willReturn('https://appleid.apple.com/auth/authorize');
        $provider->method('getState')->willReturn('apple-state');
        $this->request->method('getQueryParams')->willReturn(['redirect' => '/home']);

        $controller = new AppleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();
        $controller->redirectToApple($this->request, $this->response);
        $this->assertSame('apple-state', $_SESSION['oauth2state'] ?? null);
    }

    public function testAppleCallbackRejectsMissingEmailForNewUser(): void
    {
        OAuth2StateManager::store('apple-state');
        $provider = $this->createMock(Apple::class);
        $token = new AccessToken(['access_token' => 'at']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return ['sub' => 'apple-sub'];
            }

            public function getId(): string
            {
                return 'apple-sub';
            }

            public function getEmail(): ?string
            {
                return null;
            }

            public function getFirstName(): ?string
            {
                return null;
            }

            public function getLastName(): ?string
            {
                return null;
            }
        };

        $provider->method('getAccessToken')->willReturn($token);
        $provider->method('getResourceOwner')->willReturn($resourceOwner);
        $this->logins->method('getLoginByProviderId')->with('apple-sub')->willReturn(null);
        $this->request->method('getParsedBody')->willReturn(['code' => 'abc', 'state' => 'apple-state']);

        $controller = new AppleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->stream->expects($this->once())->method('write')->with($this->callback(function (string $html): bool {
            ScriptAlertResponseAssert::assertRedirectWithError(
                $html,
                'Apple did not provide an email address. If you have signed in before, use the same Apple ID. Otherwise, revoke Amtgard access in Apple ID settings and try again.',
                '/auth/login?policy'
            );
            return true;
        }));
        $controller->handleAppleCallback($this->request, $this->response);
    }

    public function testAppleCallbackFinalizesAuthorizationForNewLogin(): void
    {
        OAuth2StateManager::store('apple-state');
        $provider = $this->createMock(Apple::class);
        $token = new AccessToken(['access_token' => 'at', 'refresh_token' => 'rt']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return [
                    'sub' => 'apple-sub',
                    'email' => 'apple@example.com',
                    'name' => ['firstName' => 'Apple', 'lastName' => 'User'],
                ];
            }

            public function getId(): string
            {
                return 'apple-sub';
            }

            public function getEmail(): ?string
            {
                return 'apple@example.com';
            }

            public function getFirstName(): ?string
            {
                return 'Apple';
            }

            public function getLastName(): ?string
            {
                return 'User';
            }
        };
        $user = new TestUserEntity('uuid-apple', 'apple@example.com', 'Apple User');
        $login = new TestUserLoginEntity($user, 'hash', '', 11);

        $provider->expects($this->once())->method('getAccessToken')->with('authorization_code', ['code' => 'abc'])->willReturn($token);
        $provider->expects($this->once())->method('getResourceOwner')->with($token)->willReturn($resourceOwner);
        $this->logins->method('getLoginByProviderId')->with('apple-sub')->willReturn(null);
        $this->users->method('getUserByEmail')->with('apple@example.com')->willReturn(null);
        $this->users->expects($this->once())->method('createUserFromAppleData')->with([
            'email' => 'apple@example.com',
            'given_name' => 'Apple',
            'family_name' => 'User',
        ])->willReturn($user);
        $this->logins->expects($this->once())->method('createLoginFromAppleData')->willReturn($login);
        $this->amtgardIdpJwt->method('buildAuthorizationJwt')->with($user)->willReturn('jwt-token');
        $this->routeParser->method('urlFor')->with('resources.profile')->willReturn('/resources/profile');
        $this->request->method('getParsedBody')->willReturn(['code' => 'abc', 'state' => 'apple-state']);

        $controller = new AppleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->response->expects($this->once())->method('withStatus')->with(302)->willReturnSelf();
        $controller->handleAppleCallback($this->request, $this->response);

        $this->assertSame('uuid-apple', $_SESSION['user_id']);
        $this->assertSame('apple@example.com', $_SESSION['user_email']);
    }

    public function testAppleCallbackReusesExistingLoginWithoutEmail(): void
    {
        OAuth2StateManager::store('apple-state');
        $provider = $this->createMock(Apple::class);
        $token = new AccessToken(['access_token' => 'at', 'refresh_token' => 'rt']);
        $resourceOwner = new class {
            public function toArray(): array
            {
                return ['sub' => 'apple-sub'];
            }

            public function getId(): string
            {
                return 'apple-sub';
            }

            public function getEmail(): ?string
            {
                return null;
            }

            public function getFirstName(): ?string
            {
                return null;
            }

            public function getLastName(): ?string
            {
                return null;
            }
        };
        $user = new TestUserEntity('uuid-returning', 'apple@example.com', 'Apple User');
        $login = new TestUserLoginEntity($user, 'hash', '', 15);

        $provider->method('getAccessToken')->willReturn($token);
        $provider->method('getResourceOwner')->willReturn($resourceOwner);
        $this->logins->method('getLoginByProviderId')->with('apple-sub')->willReturn($login);
        $this->users->expects($this->never())->method('getUserByEmail');
        $this->logins->expects($this->once())->method('updateLoginTokens')->willReturn($login);
        $this->amtgardIdpJwt->method('buildAuthorizationJwt')->willReturn('jwt-token');
        $this->routeParser->method('urlFor')->willReturn('/resources/profile');
        $this->request->method('getParsedBody')->willReturn(['code' => 'abc', 'state' => 'apple-state']);

        $controller = new AppleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $controller->handleAppleCallback($this->request, $this->response);
        $this->assertSame('uuid-returning', $_SESSION['user_id']);
    }

    public function testAppleCallbackRejectsInvalidState(): void
    {
        $provider = $this->createMock(Apple::class);
        $this->request->method('getParsedBody')->willReturn(['code' => 'abc', 'state' => 'bad-state']);

        $controller = new AppleAuthController(
            $this->createMock(EntityManager::class),
            $this->users,
            $this->logins,
            $this->createMock(LoggerInterface::class),
            $this->amtgardIdpJwt,
            $provider,
        );

        $this->stream->expects($this->once())->method('write')->with($this->callback(function (string $html): bool {
            ScriptAlertResponseAssert::assertRedirectWithError($html, 'Invalid state parameter', '/auth/login');
            return true;
        }));
        $controller->handleAppleCallback($this->request, $this->response);
    }
}
