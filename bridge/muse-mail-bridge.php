<?php
/*
 * muse-mail-bridge.php — intermediate adapter for the you@your-domain.example mailbox.
 *
 * Why this exists: the mailbox password is held in Muse's credential vault
 * (connection custom.sakura-mail), but the vault's surrogate swap only works
 * inside HTTP requests, not inside IMAP/SMTP logins. This bridge is the
 * middle layer that makes the stored-credential route work anyway:
 *
 *   mail_muse.py --HTTPS + Bearer (surrogate, swapped to the real token)-->
 *   this script on the Sakura web space --IMAP/SMTP with the mailbox
 *   password from a server-side config--> the Sakura mail server.
 *
 * The vault therefore holds only this bridge's API token. The mailbox
 * password itself lives solely in mail-bridge-config.php, placed by the
 * account owner OUTSIDE the web root (one level above www). Neither secret
 * ever passes through the chat.
 *
 * Protocol: POST a JSON body {"action": "ping|check|list|read|send", ...}
 * with header "Authorization: Bearer <token>" (or "X-Bridge-Token: <token>").
 * Response is JSON shaped like the mail_muse.py helper's own output.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

function out(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $status = 200): void
{
    out(['ok' => false, 'error' => $message], $status);
}

/* ---------------------------------------------------------------- config */

$configPath = __DIR__ . '/../mail-bridge-config.php';
if (!is_file($configPath)) {
    fail('bridge config missing', 500);
}
$config = require $configPath;
foreach (['token', 'mail_user', 'mail_pass'] as $key) {
    if (empty($config[$key]) || !is_string($config[$key])) {
        fail('bridge config incomplete', 500);
    }
}
$imapHost = $config['imap_host'] ?? 'your-initial-domain.sakura.ne.jp';
$smtpHost = $config['smtp_host'] ?? 'your-initial-domain.sakura.ne.jp';
$mailUser = $config['mail_user'];
$mailPass = $config['mail_pass'];

/* ------------------------------------------------------------------ auth */

$token = '';
if (!empty($_SERVER['HTTP_AUTHORIZATION'])
    && preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'], $m)) {
    $token = trim($m[1]);
} elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])
    && preg_match('/^Bearer\s+(.+)$/i', $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $m)) {
    $token = trim($m[1]);
} elseif (!empty($_SERVER['HTTP_X_BRIDGE_TOKEN'])) {
    $token = trim($_SERVER['HTTP_X_BRIDGE_TOKEN']);
}
if ($token === '' || !hash_equals($config['token'], $token)) {
    fail('unauthorized', 401);
}

/* ----------------------------------------------------------------- input */

$input = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['action']) || !is_string($input['action'])) {
    fail('bad request: JSON body with an "action" is required', 400);
}
$action = $input['action'];

if ($action === 'ping') {
    out(['ok' => true, 'bridge' => 'muse-mail-bridge', 'version' => 1]);
}

/* ------------------------------------------------------------ IMAP client */

final class ImapClient
{
    /** @var resource */
    private $fp;
    private int $tagSeq = 0;

    public function __construct(string $host, string $user, string $pass)
    {
        $ctx = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $errno = 0;
        $errstr = '';
        $fp = stream_socket_client(
            "ssl://{$host}:993",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $ctx
        );
        if ($fp === false) {
            throw new RuntimeException("imap connect failed ({$errno})");
        }
        $this->fp = $fp;
        stream_set_timeout($this->fp, 30);
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new RuntimeException('imap greeting failed');
        }
        $this->command('LOGIN ' . self::quote($user) . ' ' . self::quote($pass));
    }

    public function __destruct()
    {
        if (is_resource($this->fp)) {
            try {
                $this->command('LOGOUT');
            } catch (Throwable $ignored) {
            }
            fclose($this->fp);
        }
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function readLine(): string
    {
        $line = fgets($this->fp);
        if ($line === false) {
            throw new RuntimeException('imap connection closed');
        }
        return $line;
    }

    private function readBytes(int $count): string
    {
        $data = '';
        $remaining = $count;
        while ($remaining > 0) {
            $chunk = fread($this->fp, min(8192, $remaining));
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('imap literal read failed');
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }
        return $data;
    }

    /*
     * Read one logical response line, inlining any {n} literals and the
     * physical line that follows them, until the line is complete.
     */
    private function readLogicalLine(): string
    {
        $buf = $this->readLine();
        while (preg_match('/\{(\d+)\}\r\n$/', $buf, $m) === 1) {
            $buf .= $this->readBytes((int) $m[1]);
            $buf .= $this->readLine();
        }
        return $buf;
    }

    /**
     * Run a tagged command.
     *
     * @return array{status: string, untagged: list<string>}
     */
    public function command(string $command): array
    {
        $tag = 'A' . (++$this->tagSeq);
        fwrite($this->fp, "{$tag} {$command}\r\n");
        $untagged = [];
        while (true) {
            $line = $this->readLogicalLine();
            if (str_starts_with($line, "{$tag} ")) {
                $status = trim($line);
                if (preg_match('/^' . $tag . ' OK/i', $status) !== 1) {
                    throw new RuntimeException('imap command failed: ' . $status);
                }
                return ['status' => $status, 'untagged' => $untagged];
            }
            $untagged[] = $line;
        }
    }

    /** @return list<string> folder names from LIST */
    public function listFolders(): array
    {
        $res = $this->command('LIST "" "*"');
        $folders = [];
        foreach ($res['untagged'] as $line) {
            $trimmed = trim($line);
            // * LIST (<flags>) "<sep>" <name> — the name may be quoted or bare.
            if (preg_match('/^\* LIST \([^)]*\) (?:"(?:[^"\\\\]|\\\\.)*"|\\S+) (.+)$/', $trimmed, $m) === 1) {
                $name = trim($m[1]);
                if (strlen($name) >= 2 && $name[0] === '"' && substr($name, -1) === '"') {
                    $name = stripcslashes(substr($name, 1, -1));
                }
                $folders[] = $name;
            }
        }
        return $folders;
    }

    /** EXAMINE INBOX read-only; returns EXISTS count. */
    public function examineInbox(): int
    {
        $res = $this->command('EXAMINE INBOX');
        foreach ($res['untagged'] as $line) {
            if (preg_match('/^\* (\d+) EXISTS/', $line, $m) === 1) {
                return (int) $m[1];
            }
        }
        return 0;
    }

    /** @return list<int> sequence numbers matching the SEARCH criteria */
    public function search(string $criteria): array
    {
        $res = $this->command('SEARCH ' . $criteria);
        foreach ($res['untagged'] as $line) {
            if (preg_match('/^\* SEARCH((?: \d+)*)/', trim($line), $m) === 1) {
                $nums = trim($m[1]);
                return $nums === '' ? [] : array_map('intval', explode(' ', $nums));
            }
        }
        return [];
    }

    /**
     * FETCH for one sequence set; returns one raw chunk per message.
     *
     * @return list<string>
     */
    public function fetch(string $sequenceSet, string $items, bool $byUid = false): array
    {
        $res = $this->command(($byUid ? 'UID FETCH ' : 'FETCH ') . $sequenceSet . ' (' . $items . ')');
        $chunks = [];
        foreach ($res['untagged'] as $line) {
            if (preg_match('/^\* \d+ FETCH /', $line) === 1) {
                $chunks[] = $line;
            }
        }
        return $chunks;
    }
}

/** Decode a possibly MIME-encoded header value and unfold it. */
function decode_header(string $raw): string
{
    $unfolded = trim((string) preg_replace('/\r?\n[ \t]+/', ' ', $raw));
    if (function_exists('mb_decode_mimeheader')) {
        return mb_decode_mimeheader($unfolded);
    }
    if (function_exists('iconv_mime_decode')) {
        $decoded = iconv_mime_decode($unfolded, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if (is_string($decoded)) {
            return $decoded;
        }
    }
    return $unfolded;
}

/** Pull one header (first occurrence) out of a raw header block. */
function header_value(string $headerBlock, string $name): string
{
    $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $headerBlock);
    if (preg_match('/^' . preg_quote($name, '/') . ':[ \t]*(.*)$/mi', $unfolded, $m) === 1) {
        return trim($m[1]);
    }
    return '';
}

/** Convert text to UTF-8 when its charset is known and mbstring exists. */
function to_utf8(string $text, string $charset): string
{
    $cs = strtolower(trim($charset));
    if ($cs === '' || $cs === 'utf-8' || $cs === 'us-ascii' || $cs === 'ascii') {
        return $text;
    }
    if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($text, 'UTF-8', $cs);
        if (is_string($converted)) {
            return $converted;
        }
    }
    return $text;
}

/** Extract the first text/plain body from a raw RFC822 message. */
function extract_plain_body(string $raw): string
{
    $split = preg_split("/\r?\n\r?\n/", $raw, 2);
    $headers = $split[0] ?? '';
    $body = $split[1] ?? '';

    $contentType = header_value($headers, 'Content-Type');
    $charset = '';
    if (preg_match('/charset="?([^";\s]+)"?/i', $contentType, $m) === 1) {
        $charset = $m[1];
    }

    if (preg_match('#^multipart/#i', $contentType) === 1
        && preg_match('/boundary="?([^";]+)"?/i', $contentType, $m) === 1) {
        $boundary = $m[1];
        $parts = preg_split('/--' . preg_quote($boundary, '/') . '(?:--)?\s*(?:\r?\n|$)/', $body);
        foreach ($parts ?: [] as $part) {
            if (trim($part) === '') {
                continue;
            }
            $found = extract_plain_body($part);
            if ($found !== '') {
                return $found;
            }
        }
        return '';
    }

    if ($contentType !== '' && preg_match('#^text/plain#i', $contentType) !== 1) {
        return '';
    }
    $encoding = strtolower(header_value($headers, 'Content-Transfer-Encoding'));
    if ($encoding === 'base64') {
        $decoded = base64_decode((string) preg_replace('/\s+/', '', $body));
        $body = $decoded === false ? '' : $decoded;
    } elseif ($encoding === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }
    return to_utf8($body, $charset);
}

/* ------------------------------------------------------------ SMTP sender */

/** Read an SMTP reply; returns [code, last line]. Handles multi-line. */
function smtp_read_reply($fp): array
{
    $code = 0;
    $last = '';
    while (true) {
        $line = fgets($fp);
        if ($line === false) {
            throw new RuntimeException('smtp connection closed');
        }
        $last = trim($line);
        if (preg_match('/^(\d{3}) /', $line, $m) === 1) {
            $code = (int) $m[1];
            break;
        }
        if (preg_match('/^(\d{3})$/', trim($line), $m) === 1) {
            $code = (int) $m[1];
            break;
        }
        if (preg_match('/^\d{3}-/', $line) !== 1) {
            break;
        }
    }
    return [$code, $last];
}

function smtp_expect($fp, int $want, string $what): void
{
    [$code, $line] = smtp_read_reply($fp);
    if ($code !== $want) {
        throw new RuntimeException("smtp {$what} failed: {$line}");
    }
}

function smtp_send(
    string $host,
    string $user,
    string $pass,
    string $from,
    string $to,
    string $subject,
    string $body
): void {
    $ctx = stream_context_create([
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $errno = 0;
    $errstr = '';
    $fp = stream_socket_client("ssl://{$host}:465", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $ctx);
    if ($fp === false) {
        throw new RuntimeException("smtp connect failed ({$errno})");
    }
    stream_set_timeout($fp, 30);
    try {
        smtp_expect($fp, 220, 'greeting');
        fwrite($fp, "EHLO {$host}\r\n");
        smtp_expect($fp, 250, 'EHLO');
        fwrite($fp, "AUTH PLAIN " . base64_encode("\0" . $user . "\0" . $pass) . "\r\n");
        smtp_expect($fp, 235, 'AUTH');
        fwrite($fp, "MAIL FROM:<{$from}>\r\n");
        smtp_expect($fp, 250, 'MAIL FROM');
        fwrite($fp, "RCPT TO:<{$to}>\r\n");
        smtp_expect($fp, 250, 'RCPT TO');
        fwrite($fp, "DATA\r\n");
        smtp_expect($fp, 354, 'DATA');

        $encodedSubject = function_exists('mb_encode_mimeheader')
            ? mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n")
            : '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = implode("\r\n", [
            "From: {$from}",
            "To: {$to}",
            "Subject: {$encodedSubject}",
            'Date: ' . date(DATE_RFC2822),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ]);
        $message = $headers . "\r\n\r\n" . chunk_split(base64_encode($body));
        fwrite($fp, $message . "\r\n.\r\n");
        smtp_expect($fp, 250, 'message');
        fwrite($fp, "QUIT\r\n");
    } finally {
        fclose($fp);
    }
}

/* ---------------------------------------------------------------- actions */

try {
    switch ($action) {
        case 'check': {
            $imap = new ImapClient($imapHost, $mailUser, $mailPass);
            $folders = $imap->listFolders();
            $imap->examineInbox();
            $total = count($imap->search('ALL'));
            $unseen = count($imap->search('UNSEEN'));
            out([
                'ok' => true,
                'user' => $mailUser,
                'folders' => $folders,
                'inbox_total' => $total,
                'inbox_unseen' => $unseen,
            ]);
            break;
        }

        case 'list': {
            $limit = max(1, min(100, (int) ($input['limit'] ?? 20)));
            $imap = new ImapClient($imapHost, $mailUser, $mailPass);
            $imap->examineInbox();
            $all = $imap->search('ALL');
            $picked = array_slice($all, -$limit);
            $messages = [];
            if ($picked !== []) {
                $chunks = $imap->fetch(
                    implode(',', $picked),
                    'UID FLAGS BODY.PEEK[HEADER.FIELDS (FROM SUBJECT DATE)]'
                );
                foreach ($chunks as $chunk) {
                    $num = null;
                    $uid = null;
                    if (preg_match('/^\* (\d+) FETCH/', $chunk, $m) === 1) {
                        $num = $m[1];
                    }
                    if (preg_match('/\bUID (\d+)/', $chunk, $m) === 1) {
                        $uid = $m[1];
                    }
                    $messages[] = [
                        'num' => $num,
                        'uid' => $uid,
                        'date' => decode_header(header_value($chunk, 'Date')),
                        'from' => decode_header(header_value($chunk, 'From')),
                        'subject' => decode_header(header_value($chunk, 'Subject')),
                    ];
                }
                $messages = array_reverse($messages);
            }
            out(['ok' => true, 'messages' => $messages]);
            break;
        }

        case 'read': {
            $uid = (string) ($input['uid'] ?? '');
            if ($uid === '' || !ctype_digit($uid)) {
                fail('bad request: numeric "uid" is required', 400);
            }
            $imap = new ImapClient($imapHost, $mailUser, $mailPass);
            $imap->examineInbox();
            $chunks = $imap->fetch($uid, 'RFC822', true);
            if ($chunks === []) {
                out(['ok' => false, 'error' => "uid {$uid} not found"]);
            }
            $chunk = $chunks[0];
            $raw = null;
            if (preg_match('/\{(\d+)\}\r\n/', $chunk, $m, PREG_OFFSET_CAPTURE) === 1) {
                $start = $m[0][1] + strlen($m[0][0]);
                $raw = substr($chunk, $start, (int) $m[1][0]);
            }
            if ($raw === null) {
                fail('could not parse fetched message');
            }
            $split = preg_split("/\r?\n\r?\n/", $raw, 2);
            $headerBlock = $split[0] ?? '';
            out([
                'ok' => true,
                'uid' => $uid,
                'date' => decode_header(header_value($headerBlock, 'Date')),
                'from' => decode_header(header_value($headerBlock, 'From')),
                'to' => decode_header(header_value($headerBlock, 'To')),
                'subject' => decode_header(header_value($headerBlock, 'Subject')),
                'body' => extract_plain_body($raw),
            ]);
            break;
        }

        case 'send': {
            $to = (string) ($input['to'] ?? '');
            $subject = (string) ($input['subject'] ?? '');
            $body = (string) ($input['body'] ?? '');
            if ($to === '' || preg_match('/^[^@\s]+@[^@\s]+$/', $to) !== 1) {
                fail('bad request: a valid "to" address is required', 400);
            }
            smtp_send($smtpHost, $mailUser, $mailPass, $mailUser, $to, $subject, $body);
            out(['ok' => true, 'from' => $mailUser, 'to' => $to, 'subject' => $subject]);
            break;
        }

        default:
            fail('unknown action: ' . $action, 400);
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
