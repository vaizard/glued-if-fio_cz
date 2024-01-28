<?php
/** @noinspection PhpUndefinedVariableInspection */
declare(strict_types=1);
use DI\Container;
use Glued\Lib\Middleware\TimerMiddleware;
use Middlewares\TrailingSlash;
use Nyholm\Psr7\Response as Psr7Response;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\MethodOverrideMiddleware;
use Zeuxisoo\Whoops\Slim\WhoopsMiddleware;
use Whoops\Handler\JsonResponseHandler;

/**
 * In Slim 4 middlewares are executed in the reverse order as they appear in middleware.php.
 * Do not change the order of the middleware below without a good thought. The first middleware
 * to kick must always be the error middleware, so it has to be at the end of this file.
 */


// TimerMiddleware injects the time needed to generate the response.
$app->add(TimerMiddleware::class);


// BodyParsingMiddleware detects the content-type and automatically decodes
// json, x-www-form-urlencoded and xml decodes the $request->getBody()
// properties into a php array and places it into $request->getParsedBody().
// See https://www.slimframework.com/docs/v4/middleware/body-parsing.html
$app->addBodyParsingMiddleware();


// TrailingSlash(false) removes the trailing from requests, for example
// `https://example.com/user/` will change into https://example.com/user.
// Optionally, setting redirect(true) enforces a 301 redirect.
//$trailingSlash = new TrailingSlash(false);
//$trailingSlash->redirect();
//$app->add($trailingSlash);


// RoutingMiddleware provides the FastRoute router. See
// https://www.slimframework.com/docs/v4/middleware/routing.html
$app->addRoutingMiddleware();


// Per the HTML standard, desktop browsers will only submit GET and POST requests, PUT
// and DELETE requests will be handled as GET. MethodOverrideMiddleware allows browsers
// to submit pseudo PUT and DELETE requests by relying on pre-determined request
// parameters, either a `X-Http-Method-Override` header, or a `_METHOD` form value
// and behave as a proper API client. This middleware must be added before
// $app->addRoutingMiddleware().
$app->add(new MethodOverrideMiddleware);


// Error handling middleware. This middleware must be added last. It will not handle
// any exceptions/errors for middleware added after it.
$jsonErrorHandler = function ($exception, $inspector) {
    global $settings;
    header("Content-Type: application/json");
    $r['code']    = $exception->getCode();
    $r['message'] = $exception->getMessage();
    $r['title']   = $inspector->getExceptionName() ;
    $r['file']    = $exception->getFile() . ' ' . $exception->getLine();
    $short        = explode('\\', $r['title']);
    $short        = (string) array_pop($short);
    $r['hint']    = "No hints, sorry.";
    $http         = '500 Internal Server Error';

    if ($short == "AuthJwtException")       { $http = '401 Unauthorized'; $r['hint'] = "Login at ".$settings['oidc']['uri']['login']; }
    if ($short == "AuthTokenException")     { $http = '401 Unauthorized'; $r['hint'] = "Login at ".$settings['oidc']['uri']['login']; }
    if ($short == "HttpNotFoundException")  { $http = '404 Not fond'; }
    if ($r['message'] == "MSSQL error.")    { $r['hint'] = sqlsrv_errors(); }

    header($_SERVER['SERVER_PROTOCOL'].' '.$http);
    echo json_encode($r, JSON_UNESCAPED_SLASHES);
    exit;
};

$app->add(new Zeuxisoo\Whoops\Slim\WhoopsMiddleware([
    'enable' => true,
    'editor' => 'phpstorm',
], [ $jsonErrorHandler ]));


