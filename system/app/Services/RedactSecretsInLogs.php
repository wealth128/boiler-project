<?php

namespace App\Services;

use Illuminate\Log\Logger;
use Monolog\Formatter\FormatterInterface;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\LogRecord;

/**
 * Last line of defence for the log files (planning/security.md, section 2, rule 9).
 *
 * The code never logs a password on purpose. But an exception message can: a
 * failed SQL statement is reported with its values filled in, so an insert into
 * `users` would write the password hash, and a program such as mysqldump may
 * repeat the login it was given. This tap (set on each file channel in
 * config/logging.php) wraps the formatter of every log handler, so the final
 * text of each log line is cleaned before it is written:
 *
 * - the APP_KEY, the database passwords, the restore-test password and the seed
 *   passwords from the settings, wherever they appear;
 * - any bcrypt password hash;
 * - the value of any name that contains password, passwd, token or secret
 *   (password, DB_PASSWORD, RESTORE_TEST_PASSWORD, remember_token, token),
 *   written as name=value, name: value or JSON ("password":"x"), also inside
 *   nested context arrays and JSON that is itself inside a JSON string.
 */
class RedactSecretsInLogs
{
    public const HIDDEN = '[hidden]';

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            if ($handler instanceof FormattableHandlerInterface) {
                $handler->setFormatter(new class($handler->getFormatter()) implements FormatterInterface
                {
                    public function __construct(private FormatterInterface $inner) {}

                    public function format(LogRecord $record): string
                    {
                        return RedactSecretsInLogs::redact((string) $this->inner->format($record));
                    }

                    public function formatBatch(array $records): string
                    {
                        return implode('', array_map(fn (LogRecord $record): string => $this->format($record), $records));
                    }
                });
            }
        }
    }

    /** The text with every known secret replaced by "[hidden]". */
    public static function redact(string $text): string
    {
        foreach (self::secrets() as $secret) {
            $text = str_replace($secret, self::HIDDEN, $text);
        }

        $text = (string) preg_replace('/\$2[abxy]\$\d{2}\$[.\/A-Za-z0-9]{53}/', self::HIDDEN, $text);

        // name, optional closing quote (also backslash-escaped, for JSON inside JSON), then = or :
        $name = '(?:[A-Za-z0-9_.\-]*(?:password|passwd|token|secret)[A-Za-z0-9_.\-]*(?:\\\\*["\'`])?\s*[=:]\s*)';
        // "plain JSON string", \"string inside a JSON string\", 'single quoted', or a bare word or number.
        $value = '("(?:[^"\\\\]|\\\\.)*"|\\\\+"(?:[^"\\\\]|\\\\(?!"))*\\\\+"|\'[^\']*\'|[^\s,)\'"}\]\\\\]+)';

        return (string) preg_replace('/(`?'.$name.')'.$value.'/i', '$1'.self::HIDDEN, $text);
    }

    /** @return list<string> */
    private static function secrets(): array
    {
        $found = [];

        $key = is_scalar(config('app.key')) ? (string) config('app.key') : '';
        $found[] = $key;
        $found[] = str_starts_with($key, 'base64:') ? substr($key, 7) : '';

        foreach ((array) config('app.previous_keys', []) as $previous) {
            $found[] = is_scalar($previous) ? (string) $previous : '';
        }

        foreach ((array) config('database.connections', []) as $connection) {
            $found[] = is_array($connection) && is_scalar($connection['password'] ?? null) ? (string) $connection['password'] : '';
        }

        $found[] = (string) config('census.restore_test.password');
        $found[] = (string) config('census.seed.admin_password');
        $found[] = (string) config('census.seed.sysadmin_password');
        $found[] = (string) config('census.seed.encoder_password');
        $found[] = (string) config('census.seed.viewer_password');

        // Very short values would blank out ordinary words.
        return array_values(array_unique(array_filter($found, fn (string $secret): bool => strlen($secret) >= 6)));
    }
}
