<?php
/**
 * @see https://notify-bot.line.me/doc/ja/
 */
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require_once __DIR__ . '/vendor/autoload.php';

if (!session_start()) {
    die('Filed to start session');
}

if (!($clientId = getenv('LINE_NOTIFY_CLIENT_ID'))) {
    die('You should set environment variable, like `export LINE_NOTIFY_CLIENT_ID="XXXXXX"');
}

if (!($clientSecret = getenv('LINE_NOTIFY_CLIENT_SECRET'))) {
    die('You should set environment variable, like `export LINE_NOTIFY_CLIENT_SECRET="XXXXXX"');
}

$container = new League\Container\Container;
$container->share('response', Zend\Diactoros\Response::class);
$container->share('request', function () {
    return Zend\Diactoros\ServerRequestFactory::fromGlobals(
        $_SERVER, $_GET, $_POST, $_COOKIE, $_FILES
    );
});
$container->share('emitter', Zend\Diactoros\Response\SapiEmitter::class);
$route = new League\Route\RouteCollection($container);

/**
 * index
 */
$route->map('GET', '/', function (ServerRequestInterface $request, ResponseInterface $response) use ($clientId) {
    if (isset($_SESSION['line_notify_access_token']) && $_SESSION['line_notify_access_token']) {
        $response->getBody()->write(<<<__EOS__
authorized. <br />access_token: {$_SESSION['line_notify_access_token']}<br />
<form action="/notify" method="POST">message<input type="text" name="message" value="" /><input type="submit" value="notify" /></form>
__EOS__
        );
        return $response;
    }

    // redirect to OAuth2 authorization endpoint
    $params = [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => sprintf('http://%s:%d/callback', $request->getServerParams()['SERVER_NAME'], $request->getServerParams()['SERVER_PORT']),
        'scope' => 'notify',
        'state' => hash('sha512', session_id()),
        'response_mode' => 'form_post',
    ];
    return $response->withStatus(302)->withHeader('Location', 'https://notify-bot.line.me/oauth/authorize?' . http_build_query($params));
});

/**
 * callback
 */
$route->map('POST', '/callback', function (ServerRequestInterface $request, ResponseInterface $response) use ($clientId, $clientSecret) {
    // TODO: error handling
    if ($request->getParsedBody()['state'] !== hash('sha512', session_id())) {
        die('Invalid access');
    }

    try {
        $r = (new \GuzzleHttp\Client())->request(
            'POST',
            'https://notify-bot.line.me/oauth/token',
            [
                'form_params' => [
                    'grant_type'    => 'authorization_code',
                    'code'          => $request->getParsedBody()['code'],
                    'redirect_uri'  => sprintf(
                        'http://%s:%d/callback',
                        $request->getServerParams()['SERVER_NAME'],
                        $request->getServerParams()['SERVER_PORT']
                    ),
                    'client_id'     => $clientId,
                    'client_secret' => $clientSecret,
                ],
            ]
        );
    } catch (\RuntimeException $e) {
        die($e->getMessage());
    }

    $_SESSION['line_notify_access_token'] = json_decode((string)$r->getBody())->access_token;
    $response->getBody()->write(<<<__EOS__
authorization has completed successfully.<br />
<a href="/">TOP</a>
__EOS__
);

    return $response;
});

/**
 * notify
 */
$route->map('POST', '/notify', function (ServerRequestInterface $request, ResponseInterface $response) {
    $r = (new \GuzzleHttp\Client())->request(
        'POST',
        'https://notify-api.line.me/api/notify',
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $_SESSION['line_notify_access_token'],
            ],
            'form_params' => [
                'message' => $request->getParsedBody()['message'],
            ],
        ]
    );

    if (json_decode((string)$r->getBody())->status !== 200) {
        die('Failed to notify');
    }

    $response->getBody()->write(<<<__EOS__
notified successfully.<br />
<a href="/">TOP</a>
__EOS__
    );
    return $response;
});


$response = $route->dispatch($container->get('request'), $container->get('response'));
$container->get('emitter')->emit($response);
