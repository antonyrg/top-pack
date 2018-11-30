<?php

use Slim\Http\Request;
use Slim\Http\Response;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

$app->get('/', function (Request $request, Response $response, array $args) {
    return $this->renderer->render($response, 'index.phtml', $args);
});

$app->get('/search', function (Request $request, Response $response, array $args) {
    $searchQuery = $request->getQueryParam('q', '');
    $client = new Client();
    $searchURI = "https://api.github.com/search/repositories?q=${searchQuery}";
    $reposDataBody = $client->get($searchURI)->getBody();
    $reposData = json_decode((string) $reposDataBody, true);
    $responseReposData = array_map(function($repo) {
        return array(
            'repo_id' => $repo['id'],
            'owner' => $repo['owner']['id'],
            'github_url' => $repo['html_url'],
            'forks' => $repo['forks_count'],
            'stars' => $repo['stargazers_count']
        );
    }, $reposData['items']);
    $responseData = array(
        "status" => "success",
        "data" => $responseReposData
    );
    return $response->withJson($responseData);
});


function getRepoPackages($db, $repo_id) {
    $sql = "SELECT Packages.id as id, Packages.name as name
            FROM RepoPackages, Packages
            WHERE
                RepoPackages.repo_id=${repo_id} AND
                Packages.id=RepoPackages.package_id";
    return $db->query($sql)->fetchall();
}

function getPackages($db, $packageNames) {
    $in = '"'.implode('","', $packageNames).'"';
    $sql = "SELECT * FROM Packages WHERE name IN ($in)";
    return $db->query($sql)->fetchAll();
}

function addPackages($db, $packageNames) {
    if(empty($packageNames)) {
        return [];
    }
    return $packageNames;
    // return sizeof($packageNames);
    // return str_repeat("(?),", sizeof($packageNames));
    $values = rtrim(str_repeat("(?),", sizeof($packageNames)), ',');
    $sql = "INSERT INTO Packages (name) VALUES ${values}";
    // return $sql;
    $query = $db->prepare($sql);
    $query->execute($packageNames);
    return getPackages($db, $packageNames);
}

function addRepoPackages($db, $repoID, $packageIDs) {
    if(empty($packageIDs)) {
        return;
    }
    $values = [];
    foreach($packageIDs as $packageID) {
        array_push($values, $repoID, $packageID);
    }
    $valuesPlaceholder = rtrim(str_repeat("(?, ?),", sizeof($packageIDs)), ',');
    $sql = "INSERT INTO RepoPackages (repo_id, package_id) VALUES ${valuesPlaceholder}";
    $query = $db->prepare($sql);
    $query->execute($values);
}

function addRepo($db, $repoData, $packagesNames) {
    $repos = getRepos($db, [$repoData['repo_id']]);
    if(isset($repos[0])) {
        $repo = $repos[0];
    } else {
        $sql = "
            INSERT INTO Repos (repo_id, owner, github_url, forks, stars)
            VALUES (${repoData['repo_id']},${repoData['owner']},"."'"."${repoData['github_url']}"."'".",${repoData['forks']},${repoData['stars']})";
        $db->query($sql);
        $repo = getRepos($db, [$repoData['repo_id']])[0];
    }
    $currentPackages = getPackages($db, $packagesNames);
    $currentPackagesNames = array_map(function($package) {
        return $package['name'];
    }, $currentPackages);
    $newPackagesNames = array_diff($packagesNames, $currentPackagesNames);
    return addPackages($db, $newPackagesNames);
    $newPackages = addPackages($db, $newPackagesNames);
    $allPackageIDs = array_map(function($package) {
        return $package['id'];
    }, array_merge($currentPackages, $newPackages));
    $currentRepoPackageIds = array_map(function($package) {
        return $package['id'];
    }, getRepoPackages($db, $repo['id']));
    $newRepoPackageIDs = array_diff($allPackageIDs, $currentRepoPackageIds);
    addRepoPackages($db, $repo['id'], $newRepoPackageIDs);
    return $repo;
}

function getRepos($db, $repo_ids) {
    $in = implode(",",$repo_ids);
    $sql = "SELECT * FROM Repos WHERE repo_id IN ($in)";
    return $db->query($sql)->fetchAll();
}

function getRepoDataFromAPI($repoID){
    $client = new Client();
    $repoAPIEndpoint = "https://api.github.com/repositories/${repoID}";
    $repoDataBody = $client->get($repoAPIEndpoint)->getBody();
    $repoDataJSON = json_decode((string) $repoDataBody, true);
    $repoData = array(
        'repo_id' => $repoDataJSON['id'],
        'owner' => $repoDataJSON['owner']['id'],
        'github_url' => $repoDataJSON['html_url'],
        'forks' => $repoDataJSON['forks_count'],
        'stars' => $repoDataJSON['stargazers_count']
    );
    return $repoData;
}

$app->get('/import', function (Request $request, Response $response, array $args) {
    $repoID = $request->getQueryParam('repo_id', '');
    $client = new Client();
    $contentRequestURI = "https://api.github.com/repositories/${repoID}/contents/package.json";
    try {
        $contentsDataBody = $client->get($contentRequestURI)->getBody();
    } catch (ClientException $e) {
        $errorInfo = array(
            "message" => "Missing package.json or repo"
        );
        $responseData = array(
            "status" => "error",
            "data" => $errorInfo
        );
        return $response->withJson($responseData);
    }
    $contentsData = json_decode((string) $contentsDataBody, true);
    $encodedContentsText = $contentsData['content'];
    $contentsText = base64_decode($encodedContentsText, true);
    $packagesData = json_decode($contentsText, true);
    $packagesNames = array_merge(
        isset($packagesData['dependencies']) ? array_keys($packagesData['dependencies']) : [],
        isset($packagesData['devDependencies']) ? array_keys($packagesData['devDependencies']) : [],
        isset($packagesData['peerDependencies']) ? array_keys($packagesData['peerDependencies']) : []
    );
    $repo = addRepo($this->db, getRepoDataFromAPI($repoID), $packagesNames);
    $responseData = array(
        "status" => "success",
        "data" => $repo
    );
    return $response->withJson($responseData);
});

$app->get('/top-packs', function (Request $request, Response $response, array $args) {
    $sql = "
        SELECT count(RepoPackages.repo_id) as dependees_count, Packages.id as id, Packages.name as name
        FROM RepoPackages, Packages
        WHERE
            Packages.id=RepoPackages.package_id
        GROUP BY Packages.id
        ORDER BY dependees_count DESC
        LIMIT 10";
    $topPacks = $this->db->query($sql)->fetchall();
    $responseData = array(
        "status" => "success",
        "data" => $topPacks
    );
    return $response->withJson($responseData);
});