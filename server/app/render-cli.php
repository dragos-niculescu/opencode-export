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
    fwrite(STDERR, "usage: php render-cli.php <session.json> [--with-attachments]\n");
    exit(2);
}
$withAttachments = in_array("--with-attachments", array_slice($argv, 2), true);

$json = file_get_contents($input);
$payload = is_string($json) ? json_decode($json, true) : null;
if (!is_array($payload)) {
    fwrite(STDERR, "error: invalid session JSON\n");
    exit(1);
}


/**
 * Make local file attachments self-contained for static HTML exports.
 *
 * OpenCode records attachments as file:// URLs but does not copy their bytes
 * into the session export. Resolve those URLs only in the local CLI renderer;
 * the web server must never be allowed to read arbitrary paths from uploads.
 */
function embed_local_attachments(array &$payload): void {
    if (!is_array($payload["messages"] ?? null)) {
        return;
    }
    foreach ($payload["messages"] as &$message) {
        if (!is_array($message) || !is_array($message["parts"] ?? null)) {
            continue;
        }
        foreach ($message["parts"] as &$part) {
            if (!is_array($part) || ($part["type"] ?? "") !== "file") {
                continue;
            }
            $url = is_string($part["url"] ?? null) ? $part["url"] : "";
            if (!str_starts_with($url, "file://")) {
                continue;
            }

            $path = rawurldecode((string)parse_url($url, PHP_URL_PATH));
            if ($path === "" || !is_file($path) || !is_readable($path)) {
                continue;
            }
            $bytes = file_get_contents($path);
            if (!is_string($bytes)) {
                continue;
            }

            $name = is_string($part["filename"] ?? null) ? $part["filename"] : basename($path);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $mime = is_string($part["mime"] ?? null) ? strtolower(trim($part["mime"])) : "";
            $byExt = [
                "html" => "text/html", "htm" => "text/html",
                "svg" => "image/svg+xml", "png" => "image/png",
                "jpg" => "image/jpeg", "jpeg" => "image/jpeg",
                "gif" => "image/gif", "webp" => "image/webp",
                "txt" => "text/plain", "md" => "text/markdown",
                "json" => "application/json", "pdf" => "application/pdf",
            ];
            // OpenCode sometimes labels HTML attachments text/plain.
            if (isset($byExt[$ext]) && ($mime === "" || $mime === "application/octet-stream" ||
                ($ext === "html" || $ext === "htm"))) {
                $mime = $byExt[$ext];
            }
            if ($mime === "") {
                $detected = function_exists("mime_content_type") ? mime_content_type($path) : false;
                $mime = is_string($detected) ? $detected : "application/octet-stream";
            }

            $part["mime"] = $mime;
            $part["data"] = "data:" . $mime . ";base64," . base64_encode($bytes);
            // Do not leak the exporting machine's absolute path into the HTML.
            $part["url"] = "";
        }
        unset($part);
    }
    unset($message);
}

if ($withAttachments) {
    embed_local_attachments($payload);
} else {
    // Canonical JSON can contain inline bytes. Exclude them unless explicitly
    // requested so ordinary HTML exports remain small.
    if (!is_array($payload["messages"] ?? null)) {
        $payload["messages"] = [];
    }
    foreach ($payload["messages"] as &$message) {
        if (!is_array($message) || !is_array($message["parts"] ?? null)) {
            continue;
        }
        foreach ($message["parts"] as &$part) {
            if (is_array($part) && ($part["type"] ?? "") === "file") {
                unset($part["data"]);
            }
        }
        unset($part);
    }
    unset($message);
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
