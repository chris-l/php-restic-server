<?php

require("restic-server.php");
date_default_timezone_set('UTC');
$restic = Restic::Instance(Array(
    "path" => "restic",
    "append_only" => false,
    "private_repos" => false
));

function page_404() {
    $restic = Restic::Instance();
    $restic->sendStatus(404); //not found
    header("Content-Type:");
    exit;
}
function route($method, $path, $fn) {
    $restic = Restic::Instance();
    $rawurl = (!empty($_SERVER['REQUEST_URL']))
        ? $_SERVER['REQUEST_URL']
        : $_SERVER['REQUEST_URI'];
    $dir = dirname($_SERVER['SCRIPT_NAME']);
    $uri = $dir != "/"
        ? str_replace($dir, '', $rawurl)
        : $rawurl;
    $uri = explode("?", $uri, 2);
    $path = "/^" . str_replace("/", "\/", $path) . "$/";
    $path = preg_replace("/\([^\)]*\)/", "([^\/]+)", $path);
    $apply = preg_match($path, $uri[0], $matches);
    $first = (sizeof($matches) < 2)
        ? null
        : $matches[1];

    if (array_key_exists("PHP_AUTH_USER", $_SERVER)) {
        $user = $_SERVER["PHP_AUTH_USER"];
    } elseif (array_key_exists("REMOTE_USER", $_SERVER)) {
        $user = $_SERVER["REMOTE_USER"];
    } elseif (array_key_exists("REDIRECT_REMOTE_USER", $_SERVER)) {
        $user = $_SERVER["REDIRECT_REMOTE_USER"];
    } elseif (array_key_exists("AUTH_USER", $_SERVER)) {
        $user = $_SERVER["AUTH_USER"];
    } elseif (array_key_exists("HTTP_AUTHORIZATION", $_SERVER)) {
        $user = $_SERVER["HTTP_AUTHORIZATION"];
    }

    if ($_SERVER["REQUEST_METHOD"] == $method && $apply == 1) {
        if ($restic->private_repos && $user !== $first) {
            $restic->sendStatus(401); //Unauthorized
            header("Content-Type:");
            exit;
        }
        call_user_func_array(Array($restic, $fn), array_slice($matches, 1));
        exit;
    }
}

route("HEAD", "/config", "checkConfig");
route("HEAD", "/(repo)/config", "checkConfig");
route("GET", "/config", "getConfig");
route("GET", "/(repo)/config", "getConfig");
route("POST", "/config", "saveConfig");
route("POST", "/(repo)/config", "saveConfig");
route("DELETE", "/config", "deleteConfig");
route("DELETE", "/(repo)/config", "deleteConfig");
route("GET", "/(type)/", "listBlobs");
route("GET", "/(repo)/(type)/", "listBlobs");
route("HEAD", "/(type)/(name)", "checkBlob");
route("HEAD", "/(repo)/(type)/(name)", "checkBlob");
route("GET", "/(type)/(name)", "getBlob");
route("GET", "/(repo)/(type)/(name)", "getBlob");
route("POST", "/(type)/(name)", "saveBlob");
route("POST", "/(repo)/(type)/(name)", "saveBlob");
route("DELETE", "/(type)/(name)", "deleteBlob");
route("DELETE", "/(repo)/(type)/(name)", "deleteBlob");
route("POST", "/", "createRepo");
route("POST", "/(repo)", "createRepo");
route("POST", "/(repo)/", "createRepo");

page_404();
