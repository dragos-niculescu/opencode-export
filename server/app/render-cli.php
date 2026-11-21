<?php
declare(strict_types=1);

/**
 * Render canonical session JSON with the same renderer and template as the
 * browser share view. Used by `opencode-export html`.
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require __DIR__ . "/lib/security.php";
require __DIR__ . "/lib/theme.php";
require __DIR__ . "/lib/render.php";

$input = $argv[1] ?? "";
if ($input === "" || !is_file($input)) {
    fwrite(STDERR, "usage: php render-cli.php <session.json>\n");
    exit(2);
}

$json = file_get_contents($input);
$payload = is_string($json) ? json_decode($json, true) : null;
if (!is_array($payload)) {
    fwrite(STDERR, "error: invalid session JSON\n");
    exit(1);
}

$updated = $payload["updated_at"] ?? $payload["created_at"] ?? time();
if (is_numeric($updated) && (int)$updated > 1000000000000) {
    $updated = intdiv((int)$updated, 1000);
}

$cfg = [
    "PUBLIC_BASE_URL"    => "",
    "YOURLS_EXPIRY_DAYS" => 7,
    "SESSION_PAGE_SIZE"  => PHP_INT_MAX,
];
$row = [
    "payload"       => json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
    ),
    "slug"          => (string)($payload["session_id"] ?? "local-export"),
    "password_hash" => null,
    "short_url"     => null,
    "updated_at"    => is_numeric($updated) ? (int)$updated : time(),
];

echo render_session($cfg, $row);
