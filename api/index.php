<?php

declare(strict_types=1);

// load functions
require_once dirname(__DIR__, 1) . "/vendor/autoload.php";
require_once "stats.php";
require_once "card.php";
require_once "cache.php";
require_once "generator.php";
require_once "profile-cards.php";

// load .env
$dotenv = \Dotenv\Dotenv::createImmutable(dirname(__DIR__, 1));
$dotenv->safeLoad();

// if environment variables are not loaded, display error
if (!isset($_ENV["TOKEN"])) {
    $message = file_exists(dirname(__DIR__, 1) . "/.env")
        ? "Missing token in config. Check Contributing.md for details."
        : ".env was not found. Check Contributing.md for details.";
    renderOutput($message, 500);
}

// set cache to refresh once per day (24 hours)
$cacheSeconds = CACHE_DURATION;
header("Expires: " . gmdate("D, d M Y H:i:s", time() + $cacheSeconds) . " GMT");
header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
header("Cache-Control: public, max-age=$cacheSeconds");

$requestPath = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
$card = strtolower(strval($_REQUEST["card"] ?? ""));
if ($card === "") {
    if (preg_match("#/(top-langs)(/|$)#", $requestPath)) {
        $card = "top-langs";
    } elseif (preg_match("#/(stats)(/|$)#", $requestPath)) {
        $card = "stats";
    }
}

$user = $_REQUEST["user"] ?? ($_REQUEST["username"] ?? null);

// redirect to demo site if user is not given
if ($user === null || $user === "") {
    if ($card === "stats" || $card === "top-langs") {
        renderOutput("GitHub username is required. Use ?username=YOUR_USERNAME", 400);
    }
    header("Location: demo/");
    exit();
}

try {
    if ($card === "stats" || $card === "top-langs") {
        $stats = generateProfileCardData(strval($user), $card, $_REQUEST);
        renderOutput($stats);
    } else {
        $stats = generateStreakStats($_REQUEST["user"] ?? $user, $_REQUEST);
        renderOutput($stats);
    }
} catch (InvalidArgumentException | AssertionError $error) {
    error_log("Error {$error->getCode()}: {$error->getMessage()}");
    if ($error->getCode() >= 500) {
        error_log($error->getTraceAsString());
    }
    renderOutput($error->getMessage(), $error->getCode());
}
