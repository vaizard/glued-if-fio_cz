<?php
/** @noinspection PhpUndefinedVariableInspection */
declare(strict_types=1);

use Casbin\Enforcer;
use Casbin\Util\BuiltinOperations;
use CasbinAdapter\Database\Adapter as DatabaseAdapter;
use DI\Container;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Glued\Lib\Auth;
use Glued\Lib\Utils;
use GuzzleHttp\Client as Guzzle;
use Keycloak\Admin\KeycloakClient;
use Sabre\Event\Emitter;

/**
 * STAGE1
 * used extend / modify the default container dependencies
 */


$container->set('events', function () {
    return new Emitter();
});


