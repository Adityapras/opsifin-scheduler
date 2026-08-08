<?php

namespace App\Services\LegacyImport;

/**
 * Membaca tabel routing `case "$TASK_TYPE" in ... esac` di gateway.sh.
 */
class GatewayParser
{
    /**
     * @return array<string, string> task type => path file job relatif terhadap BASE_DIR
     */
    public function parseRouting(string $contents): array
    {
        $routes = [];

        // "repost")\n    TARGET_SCRIPT="$BASE_DIR/jobs/repost.sh"
        $pattern = '/"([a-z0-9_]+)"\)\s*\n\s*TARGET_SCRIPT="\$BASE_DIR\/([^"]+)"/';

        if (preg_match_all($pattern, $contents, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $routes[$match[1]] = $match[2];
            }
        }

        return $routes;
    }
}
