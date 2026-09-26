<?php

/**
 * A small WordPress stand-in for the admin-post route layer.
 *
 * The unit suite has no WordPress. These stubs let a test run a real admin-post handler
 * and observe what the handler did: which capability it asked for, whether it checked the
 * nonce, and where it sent the browser. wp_die() and wp_safe_redirect() throw instead of
 * calling exit, so the test regains control.
 */

declare(strict_types=1);

namespace Foreningssystem\Tests\Support {
    final class WordPressRequest
    {
        /** @var array<string, bool> */
        public static array $capabilities = [];

        /** @var list<string> */
        public static array $nonces = [];

        /** @var list<string> */
        public static array $calls = [];

        /** @var array<string, bool> */
        public static array $uploads = [];

        public static function reset(): void
        {
            self::$capabilities = [];
            self::$nonces = [];
            self::$calls = [];
            self::$uploads = [];
            $_POST = [];
            $_GET = [];
            $_FILES = [];
            $_REQUEST = [];
        }

        public static function allow(string ...$capabilities): void
        {
            foreach ($capabilities as $capability) {
                self::$capabilities[$capability] = true;
            }
        }

        public static function acceptNonce(string $action): void
        {
            self::$nonces[] = $action;
        }

        public static function acceptUpload(string $path): void
        {
            self::$uploads[$path] = true;
        }

        public static function called(string $call): bool
        {
            return in_array($call, self::$calls, true);
        }
    }

    final class AdminHalt extends \RuntimeException
    {
        public function __construct(string $message, private readonly int $status)
        {
            parent::__construct($message);
        }

        public function status(): int
        {
            return $this->status;
        }
    }

    final class AdminRedirect extends \RuntimeException
    {
        public function __construct(private readonly string $location)
        {
            parent::__construct('redirect: ' . $location);
        }

        public function location(): string
        {
            return $this->location;
        }
    }
}

namespace Foreningssystem\Infrastructure\WordPress {

    use Foreningssystem\Tests\Support\WordPressRequest;

    /**
     * PHP only reports a genuine POST upload as uploaded, so the namespace shadows the
     * check for the files a test declares.
     */
    function is_uploaded_file(string $filename): bool
    {
        return WordPressRequest::$uploads[$filename] ?? false;
    }
}

namespace {

    use Foreningssystem\Tests\Support\AdminHalt;
    use Foreningssystem\Tests\Support\AdminRedirect;
    use Foreningssystem\Tests\Support\WordPressRequest;

    function current_user_can(string $capability): bool
    {
        WordPressRequest::$calls[] = 'current_user_can:' . $capability;

        return WordPressRequest::$capabilities[$capability] ?? false;
    }

    function check_admin_referer(string $action = '-1', string $queryArg = '_wpnonce'): bool
    {
        unset($queryArg);
        WordPressRequest::$calls[] = 'check_admin_referer:' . $action;

        if (! in_array($action, WordPressRequest::$nonces, true)) {
            throw new AdminHalt('The link you followed has expired.', 403);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $args
     */
    function wp_die(string $message = '', string $title = '', array $args = []): never
    {
        unset($title);

        throw new AdminHalt($message, (int) ($args['response'] ?? 0));
    }

    function wp_safe_redirect(string $location, int $status = 302, string $sentBy = ''): never
    {
        unset($status, $sentBy);

        throw new AdminRedirect($location);
    }

    function admin_url(string $path = ''): string
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }

    /**
     * @param array<string, string|int>|string $args
     */
    function add_query_arg(array|string $args, string $second = '', string $third = ''): string
    {
        $url = is_array($args) ? $second : $third;
        $pairs = is_array($args) ? $args : [$args => $second];
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url . $separator . http_build_query($pairs);
    }

    function nocache_headers(): void
    {
        WordPressRequest::$calls[] = 'nocache_headers';
    }

    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function sanitize_textarea_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function sanitize_key(string $key): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key)) ?? '';
    }

    function wp_unslash(mixed $value): mixed
    {
        return is_string($value) ? stripslashes($value) : $value;
    }

    function absint(mixed $value): int
    {
        return abs((int) $value);
    }

    function esc_html__(string $text, string $domain = 'default'): string
    {
        unset($domain);

        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
