<?php

use Slim\Http\Request;
use Slim\Http\Response;

// Routes

$app->get('/', function (Request $request, Response $response, array $args) {
    // Sample log message
    $this->logger->info("Slim-Skeleton '/' route");

    // Render index view
    return $this->renderer->render($response, 'index.phtml', $args);
});

$app->get('/search', function (Request $request, Response $response, array $args) {
    $searchQuery = $request->getQueryParam('q', '');
    $responseJSON = array('searchQuery' => $searchQuery);
    
    return $response->withJson($responseJSON);
});
