<?php

namespace App\Security;

use Auth0\SDK\Helpers\JWKFetcher;
use Auth0\SDK\Helpers\Tokens\AsymmetricVerifier;
use Auth0\SDK\Helpers\Tokens\TokenVerifier;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\Psr16Cache;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\PassportInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class Auth0Authenticator extends AbstractAuthenticator
{
    /**
     * @var LoggerInterface
     */
    protected $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function supports(Request $request): ?bool
    {
        return $request->headers->has('Authorization') && substr($request->headers->get('Authorization'), 0, 7) == 'Bearer ';
    }

    public function authenticate(Request $request): PassportInterface
    {
        $jwt = substr($request->headers->get('Authorization'), 7);

        if (!$jwt || $jwt == '') {
            throw new CustomUserMessageAuthenticationException('Authorization token missing');
        }

        $tokenIssuer = $_SERVER['AUTH0_TOKEN_ISSUER'];
        $tokenAudience = $_SERVER['AUTH0_TOKEN_AUDIENCE'];


        $psr6Cache = new FilesystemAdapter();
        $psr16Cache = new Psr16Cache($psr6Cache);

        $jwksFetcher = new JWKFetcher($psr16Cache);
        $jwks = $jwksFetcher->getKeys($tokenIssuer . '.well-known/jwks.json');
        $signatureVerifier = new AsymmetricVerifier($jwks);

        $tokenVerifier = new TokenVerifier($tokenIssuer, $tokenAudience, $signatureVerifier);


        try {
            $decodedToken = $tokenVerifier->verify($jwt);
            $userId = $decodedToken['sub'];
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
            throw new CustomUserMessageAuthenticationException('Invalid token');
        }

        if (!$userId) {
            throw new CustomUserMessageAuthenticationException('User ID could not be found in token');
        }

        return new SelfValidatingPassport(new UserBadge($userId, function ($userIdentifier) {
            return new InMemoryUser($userIdentifier, NULL);
        }));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return NULL;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse([
            'status' => 'error',
            'message' => $exception->getMessage(),
        ], 401);
    }
}
