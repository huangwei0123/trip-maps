<?php
/**
 * Password policy, breach checking and hashing.
 */

require_once __DIR__ . '/bootstrap.php';

const PASSWORD_MIN_LENGTH = 10;
const PASSWORD_MAX_LENGTH = 128;

/**
 * A valid bcrypt hash used only to burn the same amount of CPU when an email
 * address does not exist, so login timing does not reveal which accounts are
 * real. It is the hash of "password" and is never matched against anything
 * meaningful.
 */
const TIMING_DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

/**
 * A short offline backstop for when the breach API is unreachable. Not a
 * substitute for a real top-100k list, but it catches the worst offenders.
 */
function common_password_list(): array
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }

    $raw = <<<'PASSWORDS'
123456
password
12345678
qwerty
123456789
12345
1234
111111
1234567
dragon
123123
baseball
abc123
football
monkey
letmein
696969
shadow
master
666666
qwertyuiop
123321
mustang
1234567890
michael
654321
pussy
superman
1qaz2wsx
7777777
fuckyou
121212
000000
qazwsx
123qwe
killer
trustno1
jordan
jennifer
zxcvbnm
asdfgh
hunter
buster
soccer
harley
batman
andrew
tigger
sunshine
iloveyou
fuckme
2000
charlie
robert
thomas
hockey
ranger
daniel
starwars
klaster
112233
george
asshole
computer
michelle
jessica
pepper
1111
zxcvbn
555555
11111111
131313
freedom
777777
pass
fuck
maggie
159753
aaaaaa
ginger
princess
joshua
cheese
amanda
summer
love
ashley
6969
nicole
chelsea
biteme
matthew
access
yankees
987654321
dallas
austin
thunder
taylor
matrix
password1
password123
password12
password!
passw0rd
p@ssw0rd
qwerty123
qwerty1
abc12345
letmein1
welcome
welcome1
welcome123
admin
admin123
administrator
root
toor
changeme
secret
test123
guest
demo123
monkey123
dragon123
iloveyou1
princess1
sunshine1
football1
baseball1
superman1
PASSWORDS;

    $list = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));

    return $list;
}

/**
 * Human-readable policy violations for a candidate password.
 */
function password_problems(string $password, string $emailNorm = ''): array
{
    $problems = [];
    $length   = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);

    if ($length < PASSWORD_MIN_LENGTH) {
        $problems[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
    }
    if ($length > PASSWORD_MAX_LENGTH) {
        $problems[] = 'Password must be at most ' . PASSWORD_MAX_LENGTH . ' characters.';
    }
    if ($password !== '' && preg_match('/^(.)\1*$/u', $password)) {
        $problems[] = 'Password cannot be a single repeated character.';
    }

    $local = $emailNorm !== '' ? (strstr($emailNorm, '@', true) ?: '') : '';
    if ($local !== '' && strlen($local) >= 4 && stripos($password, $local) !== false) {
        $problems[] = 'Password cannot contain your email address.';
    }

    if (in_array(strtolower($password), common_password_list(), true)) {
        $problems[] = 'That password is too common. Please choose something less predictable.';
    }

    return $problems;
}

/**
 * Number of times the password appears in the Have I Been Pwned corpus, using
 * k-anonymity: only the first five hex characters of its SHA-1 ever leave the
 * server. Returns 0 when the API is unreachable (fail open, so a third-party
 * outage can never block sign-ups).
 */
function password_breach_count(string $password): int
{
    $sha1   = strtoupper(sha1($password));
    $prefix = substr($sha1, 0, 5);
    $suffix = substr($sha1, 5);

    $body = hibp_fetch_range($prefix);
    if ($body === null) {
        // Fail open: the local common-password list still catches the worst
        // offenders, and an API outage must never block sign-ups.
        audit('Breach check unavailable; allowing password.');

        return 0;
    }

    foreach (preg_split('/\r?\n/', $body) as $line) {
        $parts = explode(':', trim($line));
        if (count($parts) === 2 && strtoupper($parts[0]) === $suffix) {
            return (int) $parts[1];
        }
    }

    return 0;
}

/**
 * Fetch one HIBP k-anonymity range. Returns null on any failure.
 */
function hibp_fetch_range(string $prefix): ?string
{
    $url = 'https://api.pwnedpasswords.com/range/' . $prefix;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 2,
            CURLOPT_HTTPHEADER     => ['Add-Padding: true', 'User-Agent: photoonmaps-auth'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if (is_string($body) && $body !== '' && $code === 200) {
            return $body;
        }

        audit('HIBP request failed via curl: ' . ($err !== '' ? $err : 'HTTP ' . $code));
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'timeout'       => 2.0,
            'ignore_errors' => true,
            'header'        => "Add-Padding: true\r\nUser-Agent: photoonmaps-auth\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if (is_string($body) && $body !== '') {
        return $body;
    }

    $last = error_get_last();
    audit('HIBP request failed via streams: ' . ($last['message'] ?? 'unknown error'));

    return null;
}

/**
 * Hash a password. Argon2id is preferred; the bcrypt fallback pre-hashes with
 * SHA-256 so passwords longer than bcrypt's 72-byte limit are not truncated.
 */
function password_hash_new(string $password): string
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 32768,
            'time_cost'   => 3,
            'threads'     => 2,
        ]);
    }

    return password_hash(password_bcrypt_input($password), PASSWORD_BCRYPT);
}

function password_bcrypt_input(string $password): string
{
    return base64_encode(hash('sha256', $password, true));
}

function password_matches(string $password, string $hash): bool
{
    if ($hash === '') {
        return false;
    }

    // Argon2 hashes verify directly.
    if (strncmp($hash, '$argon2', 7) === 0) {
        return password_verify($password, $hash);
    }

    // bcrypt: our own hashes use the SHA-256 pre-hash, but try the raw form
    // too so externally created hashes still work.
    if (strncmp($hash, '$2y$', 4) === 0 || strncmp($hash, '$2a$', 4) === 0) {
        return password_verify($password, $hash)
            || password_verify(password_bcrypt_input($password), $hash);
    }

    return password_verify($password, $hash);
}

/**
 * True when a stored hash should be upgraded to the current algorithm.
 */
function password_needs_upgrade(string $hash): bool
{
    if (defined('PASSWORD_ARGON2ID')) {
        return password_get_info($hash)['algo'] !== PASSWORD_ARGON2ID;
    }

    return password_needs_rehash($hash, PASSWORD_BCRYPT);
}
