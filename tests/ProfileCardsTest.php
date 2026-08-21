<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once "api/card.php";
require_once "lib/profile-cards.php";

final class ProfileCardsTest extends TestCase
{
    public function testStatsCardIncludesUsernameAndCounts(): void
    {
        $svg = generateProfileStatsCard(
            [
                "cardType" => "stats",
                "name" => "unrastand",
                "login" => "unrastand",
                "stars" => 12,
                "commits" => 34,
                "prs" => 5,
                "issues" => 2,
                "contributedTo" => 8,
                "followers" => 3,
                "rank" => ["level" => "A+", "percentile" => 10],
            ],
            ["theme" => "dark", "show_icons" => "true"],
        );

        $this->assertStringContainsString("unrastand's GitHub Stats", $svg);
        $this->assertStringContainsString("Total Stars Earned", $svg);
        $this->assertStringContainsString("A+", $svg);
        $this->assertStringContainsString("#151515", $svg);
    }

    public function testTopLangsCardRendersCompactLayout(): void
    {
        $svg = generateTopLangsCard(
            [
                "cardType" => "top-langs",
                "name" => "unrastand",
                "login" => "unrastand",
                "langs" => [
                    ["name" => "PHP", "color" => "#4F5D95", "size" => 80],
                    ["name" => "JavaScript", "color" => "#f1e05a", "size" => 20],
                ],
            ],
            ["theme" => "dark", "layout" => "compact"],
        );

        $this->assertStringContainsString("Most Used Languages", $svg);
        $this->assertStringContainsString("PHP", $svg);
        $this->assertStringContainsString("JavaScript", $svg);
        $this->assertStringContainsString("#4F5D95", $svg);
    }
}
