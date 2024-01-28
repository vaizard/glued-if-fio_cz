<?php
/** @noinspection PhpUndefinedVariableInspection */
declare(strict_types=1);

use Alcohol\ISO4217;
use Casbin\Enforcer;
use Casbin\Util\BuiltinOperations;
use DI\Container;
use Facile\OpenIDClient\Client\ClientBuilder;
use Facile\OpenIDClient\Client\Metadata\ClientMetadata;
use Facile\OpenIDClient\Issuer\IssuerBuilder;
use Facile\OpenIDClient\Service\Builder\AuthorizationServiceBuilder;
use Glued\Lib\Auth;
use Glued\Lib\Exceptions\InternalException;
use Glued\Lib\Utils;
use Goutte\Client;
use Grasmash\YamlExpander\YamlExpander;
use GuzzleHttp\Client as Guzzle;
use Http\Discovery\Psr17FactoryDiscovery;
use Keiko\Uuid\Shortener\Dictionary;
use Keiko\Uuid\Shortener\Shortener;
use Keycloak\Admin\KeycloakClient;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Monolog\Processor\UidProcessor;
use Nyholm\Psr7\getParsedBody;
use Opis\JsonSchema\Validator;
use Phpfastcache\CacheManager;
use Phpfastcache\Config\ConfigurationOption;
use Phpfastcache\Helper\Psr16Adapter;
use Psr\Log\NullLogger;
use Sabre\Event\Emitter;
use Selective\Transformer\ArrayTransformer;
use Symfony\Component\Yaml\Yaml;
use voku\helper\AntiXSS;

$container->set('events', function () {
    return new Emitter();
});

$container->set('fscache', function () {
    try {
        $path = $_ENV['DATAPATH'] . '/' . basename(__ROOT__) . '/cache/psr16';
        CacheManager::setDefaultConfig(new ConfigurationOption([
            "path" => $path,
            "itemDetailedDate" => false,
        ]));
        return new Psr16Adapter('files');
    } catch (Exception $e) {
        throw new InternalException($e, "Path not writable - rerun composer configure", $e->getCode());
    }
});

$container->set('memcache', function () {
    CacheManager::setDefaultConfig(new ConfigurationOption([
        "defaultTtl" => 60,
    ]));
    return new Psr16Adapter('apcu');
});

$container->set('settings', function () {
    // Initialize
    $class_sy = new Yaml;
    $class_ye = new YamlExpander(new NullLogger());
    $ret = [];
    $routes = [];
    $seed = [
        'HOSTNAME' => $_SERVER['SERVER_NAME'] ?? gethostbyname(php_uname('n')),
        'ROOTPATH' => __ROOT__,
        'USERVICE' => basename(__ROOT__)
    ];

    // Load and parse the yaml configs. Replace yaml references with $_ENV and $seed ($_ENV has precedence)
    $files = __ROOT__ . '/vendor/vaizard/glued-lib/src/defaults.yaml';
    $yaml = file_get_contents($files);
    $array = $class_sy->parse($yaml, $class_sy::PARSE_CONSTANT);
    $refs['env'] = array_merge($seed, $_ENV);
    $ret = $class_ye->expandArrayProperties($array, $refs);

    // Read the routes
    $files = glob($ret['glued']['datapath'] . '/*/cache/routes.yaml');
    foreach ($files as $file) {
        $yaml = file_get_contents($file);
        $array = $class_sy->parse($yaml);
        $routes = array_merge($routes, $class_ye->expandArrayProperties($array)['routes']);
    }

    $ret['routes'] = $routes;
    return $ret;
});

$container->set('logger', function (Container $c) {
    $settings = $c->get('settings')['logger'];
    $logger = new Logger($settings['name']);
    $processor = new UidProcessor();
    $logger->pushProcessor($processor);
    $handler = new StreamHandler($settings['path'], $settings['level']);
    $logger->pushHandler($handler);
    return $logger;
});

$container->set('mysqli', function (Container $c) {
    $db = $c->get('settings')['db'];
    $mysqli = new mysqli($db['host'], $db['username'], $db['password'], $db['database']);
    $mysqli->set_charset($db['charset']);
    $mysqli->query("SET collation_connection = " . $db['collation']);
    return $mysqli;
});

$container->set('db', function (Container $c) {
    $mysqli = $c->get('mysqli');
    $db = new \MysqliDb($mysqli);
    return $db;
});

$container->set('transform', function () {
    return new ArrayTransformer();
});

$container->set('uuid_base62', function () {
    $shortener = Shortener::make(
        Dictionary::createAlphanumeric() // or pass your own characters set
    );
    return $shortener;
});

$container->set('uuid_base57', function () {
    $shortener = Shortener::make(
        Dictionary::createUnmistakable() // or pass your own characters set
    );
    return $shortener;
});

$container->set('antixss', function () {
    return new AntiXSS();
});

$container->set('jsonvalidator', function () {
    return new \Opis\JsonSchema\Validator;
});

$container->set('routecollector', $app->getRouteCollector());

$container->set('responsefactory', $app->getResponseFactory());

$container->set('iso4217', function() {
    return new Alcohol\ISO4217();
});

$container->set('mailer', function (Container $c) {
    $smtp = $c->get('settings')['smtp'];
    $transport = (new \Swift_SmtpTransport($smtp['addr'], $smtp['port'], $smtp['encr']))
      ->setUsername($smtp['user']) 
      ->setPassword($smtp['pass'])
      ->setStreamOptions(array('ssl' => array('allow_self_signed' => true, 'verify_peer' => false)));
    $mailer = new \Swift_Mailer($transport);
    $mailLogger = new \Swift_Plugins_Loggers_ArrayLogger();
    $mailer->registerPlugin(new \Swift_Plugins_LoggerPlugin($mailLogger));
    return $mailer;
});

$container->set('sqlsrv', function (Container $c) {
    $srv = $c->get('settings')['sqlsrv']['hostname'];
    $cnf = [
       "Database" => $c->get('settings')['sqlsrv']['database'],
       "UID" =>  $c->get('settings')['sqlsrv']['username'],
       "PWD" =>  $c->get('settings')['sqlsrv']['password']
    ];

    $conn = sqlsrv_connect($srv,$cnf);
    if ($conn) {
        return $conn;
    }
    throw new Exception("MSSQL error.");
});

// *************************************************
// GLUED CLASSES ***********************************
// ************************************************* 

$container->set('auth', function (Container $c) {
    return new Auth($c->get('settings'), 
                    $c->get('db'), 
                    $c->get('logger'), 
                    $c->get('events'),
                    $c->get('enforcer'),
                    $c->get('fscache'),
                    $c->get('utils')
                );
});

$container->set('utils', function (Container $c) {
    return new Utils($c->get('db'), $c->get('settings'), $c->get('routecollector'));
});

/*
$container->set('stor', function (Container $c) {
    return new Stor($c->get('db'));
});
*/
$container->set('crypto', function () {
    // TODO cleanup codebase from Crypto initialization
    return new Glued\Classes\Crypto\Crypto();
});

$container->set('reqfactory', function () {
    return Psr17FactoryDiscovery::findUriFactory();
});

$container->set('urifactory', function () {
    return Psr17FactoryDiscovery::findRequestFactory();
});

$container->set('guzzle', function () {
    return new Guzzle();
});
