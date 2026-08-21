<?php

declare(strict_types=1);

/**
 * Fetch GitHub profile stats and top languages, and render SVG cards.
 * These helpers are included by api/index.php and are not Vercel functions.
 */

/**
 * Run a GitHub GraphQL query and return the decoded response
 *
 * @param string $query GraphQL query
 * @return stdClass Decoded GraphQL response
 */
function executeGitHubGraphQL(string $query): stdClass
{
    $token = getGitHubToken();
    $handle = getGraphQLCurlHandle($query, $token);
    $contents = curl_exec($handle);
    $decoded = is_string($contents) ? json_decode($contents) : null;

    if (empty($decoded) || empty($decoded->data) || !empty($decoded->errors)) {
        $message = $decoded->errors[0]->message ?? ($decoded->message ?? "An API error occurred.");
        $errorType = $decoded->errors[0]->type ?? "";
        if (curl_errno($handle) === 60) {
            throw new AssertionError("You don't have a valid SSL Certificate installed or XAMPP.", 500);
        }
        if (curl_errno($handle)) {
            throw new AssertionError("cURL error: " . curl_error($handle), 500);
        }
        if ($errorType === "NOT_FOUND") {
            throw new InvalidArgumentException("Could not find a user with that name.", 404);
        }
        if (str_contains($message, "rate limit exceeded")) {
            removeGitHubToken($token);
        }
        $token = getGitHubToken();
        $handle = getGraphQLCurlHandle($query, $token);
        $contents = curl_exec($handle);
        $decoded = is_string($contents) ? json_decode($contents) : null;
        if (empty($decoded) || empty($decoded->data)) {
            $message = $decoded->errors[0]->message ?? ($decoded->message ?? "An API error occurred.");
            if (str_contains($message, "rate limit exceeded")) {
                removeGitHubToken($token);
            }
            throw new AssertionError($message, 500);
        }
    }

    return $decoded;
}

/**
 * Fetch owned repositories used for stars and language totals
 *
 * @param string $user GitHub username
 * @return array{stars:int,langs:array<string,array{name:string,color:string,size:int}>}
 */
function fetchOwnedRepoTotals(string $user): array
{
    $stars = 0;
    $langs = [];
    $cursor = null;
    $pages = 0;

    do {
        $afterArg = $cursor === null ? "" : ", after: \"{$cursor}\"";
        $query = "query {
            user(login: \"{$user}\") {
                repositories(first: 100, ownerAffiliations: OWNER, isFork: false, orderBy: {field: STARGAZERS, direction: DESC}{$afterArg}) {
                    pageInfo { hasNextPage endCursor }
                    nodes {
                        stargazerCount
                        languages(first: 10, orderBy: {field: SIZE, direction: DESC}) {
                            edges {
                                size
                                node { name color }
                            }
                        }
                    }
                }
            }
        }";
        $decoded = executeGitHubGraphQL($query);
        $repos = $decoded->data->user->repositories ?? null;
        if ($repos === null) {
            throw new AssertionError("Failed to retrieve repositories. This is likely a GitHub API issue.", 500);
        }
        foreach ($repos->nodes ?? [] as $repo) {
            $stars += intval($repo->stargazerCount ?? 0);
            foreach ($repo->languages->edges ?? [] as $edge) {
                $name = strval($edge->node->name ?? "");
                if ($name === "") {
                    continue;
                }
                if (!isset($langs[$name])) {
                    $langs[$name] = [
                        "name" => $name,
                        "color" => strval($edge->node->color ?? "#8B949E"),
                        "size" => 0,
                    ];
                }
                $langs[$name]["size"] += intval($edge->size ?? 0);
            }
        }
        $hasNextPage = (bool) ($repos->pageInfo->hasNextPage ?? false);
        $cursor = $repos->pageInfo->endCursor ?? null;
        $pages++;
    } while ($hasNextPage && $cursor && $pages < 3);

    return ["stars" => $stars, "langs" => $langs];
}

/**
 * Calculate a rank letter similar to github-readme-stats
 *
 * @param array<string,int> $stats
 * @return array{level:string,percentile:float}
 */
function calculateGitHubRank(array $stats): array
{
    $items = [
        ["weight" => 1, "median" => 250, "value" => $stats["commits"] ?? 0],
        ["weight" => 3, "median" => 50, "value" => $stats["prs"] ?? 0],
        ["weight" => 1, "median" => 25, "value" => $stats["issues"] ?? 0],
        ["weight" => 4, "median" => 50, "value" => $stats["stars"] ?? 0],
        ["weight" => 1, "median" => 10, "value" => $stats["followers"] ?? 0],
        ["weight" => 2, "median" => 25, "value" => $stats["contributedTo"] ?? 0],
    ];
    $totalWeight = 0;
    foreach ($items as $item) {
        $totalWeight += $item["weight"];
    }
    $rankScore = 1.0;
    foreach ($items as $item) {
        $rankScore *= pow(1 - $item["weight"] / $totalWeight, $item["value"] / $item["median"]);
    }
    $percentile = $rankScore * 100;
    $levels = [
        ["S", 1],
        ["A+", 18],
        ["A-", 37.5],
        ["B+", 56],
        ["B-", 75],
        ["C+", 87.5],
        ["C-", 100],
    ];
    $level = "C-";
    foreach ($levels as [$name, $threshold]) {
        if ($percentile <= $threshold) {
            $level = $name;
            break;
        }
    }
    return ["level" => $level, "percentile" => $percentile];
}

/**
 * Fetch profile stats used by the GitHub stats card
 *
 * @param string $user GitHub username
 * @return array<string,mixed>
 */
function fetchGitHubProfileStats(string $user): array
{
    if (!isWhitelisted($user)) {
        throw new InvalidArgumentException("User not in whitelist.", 403);
    }

    $query = "query {
        user(login: \"{$user}\") {
            name
            login
            followers { totalCount }
            issues { totalCount }
            pullRequests { totalCount }
            contributionsCollection {
                totalCommitContributions
                restrictedContributionsCount
                contributionCalendar {
                    totalContributions
                }
            }
        }
    }";
    $decoded = executeGitHubGraphQL($query);
    $githubUser = $decoded->data->user ?? null;
    if ($githubUser === null) {
        throw new InvalidArgumentException("Could not find a user with that name.", 404);
    }

    $repoTotals = fetchOwnedRepoTotals($user);
    $commits =
        intval($githubUser->contributionsCollection->totalCommitContributions ?? 0) +
        intval($githubUser->contributionsCollection->restrictedContributionsCount ?? 0);
    $calendarTotal = intval(
        $githubUser->contributionsCollection->contributionCalendar->totalContributions ?? 0,
    );
    $restricted = intval($githubUser->contributionsCollection->restrictedContributionsCount ?? 0);
    $stats = [
        "cardType" => "stats",
        "name" => strval($githubUser->name ?: $githubUser->login),
        "login" => strval($githubUser->login),
        "stars" => $repoTotals["stars"],
        "commits" => $commits,
        "prs" => intval($githubUser->pullRequests->totalCount ?? 0),
        "issues" => intval($githubUser->issues->totalCount ?? 0),
        "contributedTo" => $calendarTotal + $restricted,
        "followers" => intval($githubUser->followers->totalCount ?? 0),
    ];
    $stats["rank"] = calculateGitHubRank($stats);
    return $stats;
}

/**
 * Fetch top languages used by the compact languages card
 *
 * @param string $user GitHub username
 * @param int $langsCount Maximum languages to include
 * @return array<string,mixed>
 */
function fetchGitHubTopLangs(string $user, int $langsCount = 6): array
{
    if (!isWhitelisted($user)) {
        throw new InvalidArgumentException("User not in whitelist.", 403);
    }

    $repoTotals = fetchOwnedRepoTotals($user);
    $langs = array_values($repoTotals["langs"]);
    usort($langs, fn(array $a, array $b): int => $b["size"] <=> $a["size"]);
    $langs = array_slice($langs, 0, max(1, $langsCount));

    return [
        "cardType" => "top-langs",
        "name" => $user,
        "login" => $user,
        "langs" => $langs,
    ];
}

/**
 * Cached fetch for profile stats or top languages
 *
 * @param string $user GitHub username
 * @param string $cardType "stats" or "top-langs"
 * @param array<string,mixed> $params Request parameters
 * @return array<string,mixed>
 */
function generateProfileCardData(string $user, string $cardType, array $params = []): array
{
    $user = preg_replace("/[^a-zA-Z0-9\-]/", "", $user);
    if ($user === "") {
        throw new InvalidArgumentException("GitHub username is required.", 400);
    }

    $langsCount = isset($params["langs_count"]) ? intval($params["langs_count"]) : 6;
    $cacheOptions = [
        "card" => $cardType,
        "langs_count" => $langsCount,
        "rank_scale" => "signed-v3",
    ];
    $useCache = !isset($_SERVER["DISABLE_CACHE"]) || strtolower(strval($_SERVER["DISABLE_CACHE"])) !== "true";
    $cached = $useCache ? getCachedStats($user, $cacheOptions) : null;
    if (is_array($cached) && ($cached["cardType"] ?? "") === $cardType) {
        return $cached;
    }

    $data =
        $cardType === "top-langs"
            ? fetchGitHubTopLangs($user, $langsCount)
            : fetchGitHubProfileStats($user);
    if ($useCache) {
        setCachedStats($user, $cacheOptions, $data);
    }
    return $data;
}

/**
 * Escape text for SVG output
 */
function escapeSvgText(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, "UTF-8");
}

/**
 * Built-in octicon-style icons for the stats card
 *
 * @return array<string,string>
 */
function profileStatIcons(): array
{
    return [
        "stars" =>
            "<path d='M8 .25a.75.75 0 01.673.418l1.882 3.815 4.21.612a.75.75 0 01.416 1.279l-3.046 2.97.719 4.192a.75.75 0 01-1.088.791L8 12.347l-3.766 1.98a.75.75 0 01-1.088-.79l.72-4.194L.818 6.374a.75.75 0 01.416-1.28l4.21-.611L7.327.668A.75.75 0 018 .25z'/>",
        "commits" =>
            "<path d='M11.93 8.5a4.002 4.002 0 01-7.86 0H.75a.75.75 0 010-1.5h3.32a4.002 4.002 0 017.86 0h3.32a.75.75 0 010 1.5zm-1.43-.75a2.5 2.5 0 10-5 0 2.5 2.5 0 005 0z'/>",
        "prs" =>
            "<path d='M1.5 3.25a2.25 2.25 0 113 2.122v5.256a2.251 2.251 0 11-1.5 0V5.372A2.25 2.25 0 011.5 3.25zm5.677-.177L9.573.677A.25.25 0 0110 .854V2.5h1A2.5 2.5 0 0113.5 5v5.628a2.251 2.251 0 11-1.5 0V5A1 1 0 0011 4H10v1.646a.25.25 0 01-.427.177L7.177 3.427a.25.25 0 010-.354zM3.75 2.5a.75.75 0 100 1.5.75.75 0 000-1.5zm0 9.5a.75.75 0 100 1.5.75.75 0 000-1.5zm8.25.75a.75.75 0 10-1.5 0 .75.75 0 001.5 0z'/>",
        "issues" =>
            "<path d='M8 9.5a1.5 1.5 0 100-3 1.5 1.5 0 000 3z'/><path d='M8 0a8 8 0 100 16A8 8 0 008 0zM1.5 8a6.5 6.5 0 1113 0 6.5 6.5 0 01-13 0z'/>",
        "contributedTo" =>
            "<path d='M2 2.5A2.5 2.5 0 014.5 0h8.75a.75.75 0 01.75.75v12.5a.75.75 0 01-.75.75h-2.5a.75.75 0 110-1.5h1.75v-2h-8a1 1 0 00-.714 1.7.75.75 0 01-1.072 1.05A2.495 2.495 0 012 11.5zm10.5-1h-8a1 1 0 00-1 1v6.708A2.486 2.486 0 014.5 9h8zM5 12.25a.25.25 0 01.25-.25h3.5a.25.25 0 01.25.25v3.25a.25.25 0 01-.4.2l-1.45-1.087a.249.249 0 00-.3 0L5.4 15.7a.25.25 0 01-.4-.2z'/>",
    ];
}

/**
 * Render the GitHub stats SVG card
 *
 * @param array<string,mixed> $stats
 * @param array<string,mixed>|null $params
 */
function generateProfileStatsCard(array $stats, ?array $params = null): string
{
    $params = $params ?? $_REQUEST;
    $theme = getRequestedTheme($params);
    $localeCode = $params["locale"] ?? "en";
    $useShortNumbers = ($params["short_numbers"] ?? "") === "true";
    $showIcons = ($params["show_icons"] ?? "") === "true";
    $hideRank = ($params["hide_rank"] ?? "") === "true";
    $borderRadius = $params["border_radius"] ?? 4.5;
    $cardWidth = 495;
    $cardHeight = 195;
    $title = escapeSvgText(($stats["name"] ?? $stats["login"] ?? "GitHub") . "'s GitHub Stats");
    $rankLevel = escapeSvgText(strval($stats["rank"]["level"] ?? "C-"));
    $percentile = floatval($stats["rank"]["percentile"] ?? 100);
    $rankProgress = max(0, min(1, 1 - $percentile / 100));
    $circumference = 2 * M_PI * 40;
    $dashOffset = $circumference * (1 - $rankProgress);

    $rows = [
        ["stars", "Total Stars Earned", intval($stats["stars"] ?? 0)],
        ["commits", "Total Commits (this year)", intval($stats["commits"] ?? 0)],
        ["prs", "Total PRs", intval($stats["prs"] ?? 0)],
        ["issues", "Total Issues", intval($stats["issues"] ?? 0)],
        ["contributedTo", "Total Contributions (last year)", intval($stats["contributedTo"] ?? 0)],
    ];
    $icons = profileStatIcons();
    $iconColor = $theme["ring"];
    $labelX = $showIcons ? 50 : 25;
    $items = "";
    foreach ($rows as $index => [$key, $label, $value]) {
        $y = 55 + $index * 25;
        $formatted = escapeSvgText(formatNumber($value, $localeCode, $useShortNumbers));
        $icon = "";
        if ($showIcons && isset($icons[$key])) {
            $icon = "<g transform='translate(25, " . ($y - 12) . ")' fill='{$iconColor}'>{$icons[$key]}</g>";
        }
        $delay = 0.3 + $index * 0.1;
        $items .= "
            {$icon}
            <text x='{$labelX}' y='{$y}' fill='{$theme["sideLabels"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='14px' style='opacity: 0; animation: fadein 0.4s linear forwards {$delay}s'>
                {$label}
            </text>
            <text x='330' y='{$y}' fill='{$theme["sideNums"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='14px' font-weight='700' text-anchor='end' style='opacity: 0; animation: fadein 0.4s linear forwards {$delay}s'>
                {$formatted}
            </text>";
    }

    $rankSvg = "";
    if (!$hideRank) {
        $rankSvg = "
            <g transform='translate(410, 100)' style='opacity: 0; animation: fadein 0.5s linear forwards 0.8s'>
                <circle r='40' fill='none' stroke='{$theme["stroke"]}' stroke-width='6'/>
                <circle r='40' fill='none' stroke='{$theme["ring"]}' stroke-width='6' stroke-linecap='round'
                    stroke-dasharray='{$circumference}' stroke-dashoffset='{$dashOffset}' transform='rotate(-90)'/>
                <text x='0' y='8' text-anchor='middle' fill='{$theme["currStreakNum"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='20px' font-weight='700'>{$rankLevel}</text>
            </g>";
    }

    return "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$cardWidth} {$cardHeight}' width='{$cardWidth}px' height='{$cardHeight}px' role='img' aria-labelledby='title'>
        <title id='title'>{$title}</title>
        <style>
            @keyframes fadein { from { opacity: 0; } to { opacity: 1; } }
        </style>
        <defs>
            <clipPath id='outer_rectangle'>
                <rect width='{$cardWidth}' height='{$cardHeight}' rx='{$borderRadius}'/>
            </clipPath>
            {$theme["backgroundGradient"]}
        </defs>
        <g clip-path='url(#outer_rectangle)'>
            <rect stroke='{$theme["border"]}' fill='{$theme["background"]}' rx='{$borderRadius}' x='0.5' y='0.5' width='" .
        ($cardWidth - 1) .
        "' height='" .
        ($cardHeight - 1) .
        "'/>
            <text x='25' y='32' fill='{$theme["currStreakLabel"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='18px' font-weight='600'>{$title}</text>
            {$items}
            {$rankSvg}
        </g>
    </svg>";
}

/**
 * Render the compact top languages SVG card
 *
 * @param array<string,mixed> $data
 * @param array<string,mixed>|null $params
 */
function generateTopLangsCard(array $data, ?array $params = null): string
{
    $params = $params ?? $_REQUEST;
    $theme = getRequestedTheme($params);
    $borderRadius = $params["border_radius"] ?? 4.5;
    $layout = strtolower(strval($params["layout"] ?? "compact"));
    $langs = $data["langs"] ?? [];
    $total = 0;
    foreach ($langs as $lang) {
        $total += intval($lang["size"] ?? 0);
    }
    $title = escapeSvgText("Most Used Languages");
    $cardWidth = 495;
    $cardHeight = 195;

    $bar = "";
    $labels = "";
    if ($total > 0) {
        $x = 25;
        $barWidth = $cardWidth - 50;
        foreach ($langs as $lang) {
            $pct = intval($lang["size"]) / $total;
            $width = max(2, $pct * $barWidth);
            $color = escapeSvgText($lang["color"] ?: "#8B949E");
            $bar .= "<rect x='{$x}' y='58' width='{$width}' height='12' rx='2' fill='{$color}'/>";
            $x += $width;
        }
        if ($layout === "compact") {
            foreach ($langs as $index => $lang) {
                $col = $index % 2;
                $row = intdiv($index, 2);
                $lx = 40 + $col * 230;
                $ly = 100 + $row * 28;
                $name = escapeSvgText(strval($lang["name"]));
                $percent = number_format((intval($lang["size"]) / $total) * 100, 2);
                $color = escapeSvgText($lang["color"] ?: "#8B949E");
                $labels .= "
                    <circle cx='{$lx}' cy='" .
                    ($ly - 4) .
                    "' r='5' fill='{$color}'/>
                    <text x='" .
                    ($lx + 14) .
                    "' y='{$ly}' fill='{$theme["sideLabels"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='14px'>{$name} {$percent}%</text>";
            }
        } else {
            foreach ($langs as $index => $lang) {
                $y = 70 + $index * 40;
                $name = escapeSvgText(strval($lang["name"]));
                $percent = number_format((intval($lang["size"]) / $total) * 100, 2);
                $color = escapeSvgText($lang["color"] ?: "#8B949E");
                $width = (intval($lang["size"]) / $total) * ($cardWidth - 50);
                $labels .= "
                    <text x='25' y='{$y}' fill='{$theme["sideLabels"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='14px'>{$name}</text>
                    <text x='" .
                    ($cardWidth - 25) .
                    "' y='{$y}' text-anchor='end' fill='{$theme["sideNums"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='14px'>{$percent}%</text>
                    <rect x='25' y='" .
                    ($y + 8) .
                    "' width='" .
                    ($cardWidth - 50) .
                    "' height='8' rx='4' fill='{$theme["stroke"]}'/>
                    <rect x='25' y='" .
                    ($y + 8) .
                    "' width='{$width}' height='8' rx='4' fill='{$color}'/>";
            }
        }
    } else {
        $labels = "<text x='25' y='80' fill='{$theme["dates"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='14px'>No language data found.</text>";
    }

    $compactBar = $layout === "compact" && $total > 0 ? "<g>{$bar}</g>" : "";

    return "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$cardWidth} {$cardHeight}' width='{$cardWidth}px' height='{$cardHeight}px' role='img' aria-labelledby='title'>
        <title id='title'>{$title}</title>
        <style>
            @keyframes fadein { from { opacity: 0; } to { opacity: 1; } }
        </style>
        <defs>
            <clipPath id='outer_rectangle'>
                <rect width='{$cardWidth}' height='{$cardHeight}' rx='{$borderRadius}'/>
            </clipPath>
            {$theme["backgroundGradient"]}
        </defs>
        <g clip-path='url(#outer_rectangle)'>
            <rect stroke='{$theme["border"]}' fill='{$theme["background"]}' rx='{$borderRadius}' x='0.5' y='0.5' width='" .
        ($cardWidth - 1) .
        "' height='" .
        ($cardHeight - 1) .
        "'/>
            <text x='25' y='32' fill='{$theme["currStreakLabel"]}' font-family='\"Segoe UI\", Ubuntu, sans-serif' font-size='18px' font-weight='600'>{$title}</text>
            {$compactBar}
            {$labels}
        </g>
    </svg>";
}
