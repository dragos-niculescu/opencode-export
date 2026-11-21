<?php
declare(strict_types=1);

require_once __DIR__ . '/Parsedown.php';

function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function render_session(array $cfg, array $row, int $page = 1, bool $edit = false, int $history = 0): string {
    $payload = json_decode($row['payload'], true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $pd = new Parsedown();
    $pd->setSafeMode(true);
    $nonce = security_nonce();
    $base  = rtrim((string)$cfg['PUBLIC_BASE_URL'], '/');

    // Browser shares use 20 messages per page. Static exports override this
    // so one HTML file contains the entire conversation.
    $per_page   = max(1, (int)($cfg["SESSION_PAGE_SIZE"] ?? 20));
    $all_msgs   = is_array($payload['messages'] ?? null) ? $payload['messages'] : [];
    $total_msgs = count($all_msgs);
    $pages      = max(1, (int)ceil($total_msgs / $per_page));
    $page       = max(1, min($pages, $page));
    $offset     = ($page - 1) * $per_page;
    $msg_start  = $offset + 1;
    $msg_end    = min($offset + $per_page, $total_msgs);

    $payload['messages'] = array_slice($all_msgs, $offset, $per_page);

    ob_start();
    include __DIR__ . '/../templates/session.php';
    return ob_get_clean();
}

function render_part(array $part, Parsedown $pd): string {
    $type = is_string($part['type'] ?? null) ? $part['type'] : 'unknown';

    switch ($type) {
        case 'text':
            $text = is_string($part['text'] ?? null) ? $part['text'] : '';
            return '<div class="part-text">' . $pd->text($text) . '</div>';

        case 'reasoning':
            $text = h(is_string($part['text'] ?? null) ? $part['text'] : '');
            return '<details class="part-reasoning">
                <summary>&#x1F4AD; Reasoning</summary>
                <div class="reasoning-body"><pre>' . $text . '</pre></div>
            </details>';

        case 'tool':
            return render_tool($part);

        case 'file':
            return render_file_part($part);

        case 'step-start':
        case 'step-finish':
            return '';

        case 'compaction':
            $auto = ($part['auto'] ?? false) ? 'auto' : 'manual';
            return '<div class="part-compaction">
                <span class="compaction-icon">&#x267B;</span>
                <span class="compaction-label">Context compacted (' . $auto . ') — content before this point has been replaced with a summary</span>
            </div>';

        case 'note':
        case 'system':
            $text = is_string($part['text'] ?? null) ? $part['text'] : '';
            if ($text === '') {
                return '';
            }
            return '<div class="part-note">' . h($text) . '</div>';

        default:
            $json = h(json_encode($part, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
            return '<details class="part-unknown">
                <summary>&#x26A0;&#xFE0F; Unknown part type: ' . h($type) . '</summary>
                <pre class="unknown-json">' . $json . '</pre>
            </details>';
    }
}

function render_tool(array $part): string {
    $name   = is_string($part['tool'] ?? null) ? $part['tool']
            : (is_string($part['name'] ?? null) ? $part['name'] : 'unknown');
    $tool   = h($name);
    $state  = is_array($part['state'] ?? null) ? $part['state'] : [];
    $status = h(is_string($state['status'] ?? null) ? $state['status'] : 'unknown');

    $statusClass = match ($state['status'] ?? '') {
        'completed' => 'status-ok',
        'error'     => 'status-error',
        default     => 'status-pending',
    };

    // Older clients store the result in state.output; current OpenCode keeps it
    // as an array of content parts in state.content.
    $output = $state['output'] ?? null;
    if (($output === null || $output === '') && is_array($state['content'] ?? null)) {
        $chunks = [];
        foreach ($state['content'] as $c) {
            if (is_string($c)) {
                $chunks[] = $c;
            } elseif (is_array($c) && is_string($c['text'] ?? null)) {
                $chunks[] = $c['text'];
            } elseif (is_array($c)) {
                $chunks[] = (string)json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            }
        }
        $output = implode("\n\n", $chunks);
    }

    $error = $state['error'] ?? null;
    if (is_array($error)) {
        $error = $error['message'] ?? json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    $html = '<div class="part-tool ' . $statusClass . '">';
    $html .= '<div class="tool-header"><span class="tool-name">&#x1F527; ' . $tool . '</span> <span class="tool-status">' . $status . '</span></div>';

    if (isset($state['input'])) {
        $inputJson = h(
            is_string($state['input']) ? $state['input'] : json_encode($state['input'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)
        );
        $html .= '<details class="tool-section" open><summary>Input</summary><pre class="tool-input"><code class="language-json">' . $inputJson . '</code></pre></details>';
    }

    if ($output !== null && $output !== '') {
        $outputHtml = h(is_scalar($output) ? (string)$output : (string)json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $html .= '<details class="tool-section" open><summary>Output</summary><pre class="tool-output"><code>' . $outputHtml . '</code></pre></details>';
    }

    if ($error !== null && $error !== '') {
        $errorHtml = h(is_scalar($error) ? (string)$error : (string)json_encode($error, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR));
        $html .= '<div class="tool-error"><strong>Error:</strong> ' . $errorHtml . '</div>';
    }

    $html .= '</div>';
    return $html;
}

function render_file_part(array $part): string {
    $filenameRaw = is_string($part['filename'] ?? null) ? $part['filename'] : 'file';
    $mimeRaw     = is_string($part['mime'] ?? null) ? strtolower(trim($part['mime'])) : '';
    $url         = is_string($part['url'] ?? null) ? $part['url'] : '';
    $data        = is_string($part['data'] ?? null) ? $part['data'] : '';

    $filename = h($filenameRaw);
    $mime     = h($mimeRaw);
    $embedded = '';

    // Accept only base64 data URLs whose declared type matches the attachment.
    // This excludes javascript: URLs and malformed data supplied in share JSON.
    if ($data !== '' &&
        preg_match('#^data:([a-z0-9][a-z0-9.+-]*/[a-z0-9][a-z0-9.+-]*);base64,([A-Za-z0-9+/=\r\n]+)$#i', $data, $m) &&
        ($mimeRaw === '' || hash_equals($mimeRaw, strtolower($m[1])))) {
        $mimeRaw = strtolower($m[1]);
        $mime = h($mimeRaw);
        $embedded = $data;
    }

    $label = '&#x1F4CE; <strong>' . $filename . '</strong>';
    if ($mime !== '') {
        $label .= ' <span class="file-mime">(' . $mime . ')</span>';
    }

    if ($embedded !== '') {
        $safeData = h($embedded);
        $isImage = str_starts_with($mimeRaw, 'image/');
        $isHtml  = in_array($mimeRaw, ['text/html', 'application/xhtml+xml'], true);

        $html = '<details class="part-file attachment-embedded">';
        $html .= '<summary>' . $label . ' <span class="attachment-hint">embedded</span></summary>';
        $html .= '<div class="attachment-actions">';
        $html .= '<a href="' . $safeData . '" download="' . $filename . '">Download</a>';
        if ($isHtml) {
            $html .= ' <a href="' . $safeData . '" target="_blank" rel="noopener noreferrer">Open in new tab</a>';
        }
        $html .= '</div>';

        if ($isImage) {
            $html .= '<a class="attachment-image-link" href="' . $safeData . '" target="_blank" rel="noopener noreferrer">';
            $html .= '<img class="attachment-image" src="' . $safeData . '" alt="' . $filename . '" loading="lazy">';
            $html .= '</a>';
        } elseif ($isHtml) {
            // No allow-same-origin: embedded scripts can run, but the attachment
            // remains in an opaque origin and cannot reach the conversation DOM.
            $html .= '<iframe class="attachment-frame" sandbox="allow-scripts allow-forms allow-modals allow-popups"';
            $html .= ' src="' . $safeData . '" title="' . $filename . '" loading="lazy"></iframe>';
        } else {
            $html .= '<div class="attachment-note">Preview is not available for this file type.</div>';
        }

        $html .= '</details>';
        return $html;
    }

    $html = '<div class="part-file">' . $label;
    if ($url !== '') {
        if (preg_match('#^https?://#i', $url)) {
            $html .= ' <a href="' . h($url) . '" target="_blank" rel="noopener noreferrer">Open</a>';
        } else {
            $html .= ' <span class="file-mime">' . h($url) . '</span>';
        }
    }
    $html .= '</div>';
    return $html;
}

/**
 * Model may be a plain string, an object {providerID, id|modelID, variant}
 * or (from older clients) that object serialised as a JSON string.
 */
function format_model(mixed $model): string {
    if (is_string($model)) {
        $trimmed = trim($model);
        if ($trimmed !== '' && $trimmed[0] === '{') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $model = $decoded;
            }
        }
    }
    if (is_array($model)) {
        $provider = is_string($model['providerID'] ?? null) ? $model['providerID'] : '';
        $id       = is_string($model['modelID'] ?? null) ? $model['modelID']
                  : (is_string($model['id'] ?? null) ? $model['id'] : '');
        $variant  = is_string($model['variant'] ?? null) ? $model['variant'] : '';
        $out = $provider !== '' && $id !== '' ? "$provider/$id" : ($id ?: $provider);
        return $variant !== '' ? "$out ($variant)" : $out;
    }
    return is_scalar($model) ? (string)$model : '';
}

function format_cost(mixed $cost): string {
    if (!is_numeric($cost) || (float)$cost == 0.0) {
        return '$0.00';
    }
    return '$' . number_format((float)$cost, 4);
}

function format_ts(mixed $ts): string {
    if (!is_numeric($ts)) {
        return '-';
    }
    $ts = (int)$ts;
    // timestamps may be in ms (>1e12) or s
    if ($ts > 1e12) {
        $ts = intdiv($ts, 1000);
    }
    return gmdate('Y-m-d H:i:s', $ts) . ' UTC';
}
