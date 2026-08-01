<?php

declare(strict_types=1);

namespace AaiEduHr\HeartPhrameModuleApi\Command;

use AaiEduHr\HeartPhrameModuleApi\Service\WebhookOutboxWorker;
use HeartPhrame\Config\ConfigInterface;
use InvalidArgumentException;
use RuntimeException;

use function array_slice;
use function array_values;
use function date;
use function is_dir;
use function is_file;
use function is_scalar;
use function max;
use function min;
use function mkdir;
use function preg_replace;
use function rtrim;
use function sleep;
use function str_starts_with;
use function strtolower;
use function trim;

/**
 * HR: Pruža CLI instalaciju jedine početne API migracije.
 * EN: Provides CLI installation of the single initial API migration.
 */
final readonly class HpApiCommand
{
    private const DEFAULT_MIGRATIONS_PATH = 'database/migrations';

    private const TEMPLATE_FILE = 'resources/migrations/initial_api_schema.php';

    /**
     * HR: Prima konfiguraciju host aplikacije i opcionalni webhook worker.
     * EN: Receives host-application configuration and the optional webhook worker.
     */
    public function __construct(
        private ConfigInterface $config,
        private ?WebhookOutboxWorker $webhookWorker = null,
    ) {
    }

    /**
     * HR: Usmjerava glavnu naredbu na instalaciju ili pomoć.
     * EN: Routes the main command to installation or help.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function run(array $arguments = [], array $options = []): int
    {
        $subcommand = strtolower(trim((string)($arguments[0] ?? 'help')));

        return match ($subcommand) {
            'install', 'migration:install', 'install-migration', 'scaffold' =>
                $this->installMigration(array_values(array_slice($arguments, 1)), $options),
            'webhooks:worker', 'webhook:worker' =>
                $this->webhookWorker(array_values(array_slice($arguments, 1)), $options),
            'webhooks:status', 'webhook:status' => $this->webhookStatus(),
            'help', '--help', '-h' => $this->help(),
            default => $this->unknownSubcommand($subcommand),
        };
    }

    /**
     * HR: Kopira početnu migraciju u host aplikaciju.
     * EN: Copies the initial migration into the host application.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    public function installMigration(array $arguments = [], array $options = []): int
    {
        $directory = $this->targetDirectory($options);
        $template = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::TEMPLATE_FILE;
        if (!is_file($template)) {
            throw new RuntimeException(__('Predložak API migracije nije pronađen.'));
        }

        if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException(__('Nije moguće kreirati direktorij migracija.'));
        }

        $name = $this->migrationSuffix($arguments, $options);
        $target = rtrim($directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . date('YmdHis')
            . '_'
            . $name
            . '.php';
        $content = file_get_contents($template);
        if (!is_string($content) || $content === '' || file_put_contents($target, $content) === false) {
            throw new RuntimeException(__('Nije moguće kopirati API migraciju.'));
        }

        $this->write(__('Kreirana je početna API migracija: ') . $target);
        $this->write(__('Sljedeći korak: pokreni `vendor/bin/hph orm-migrate up`.'));

        return 0;
    }

    /**
     * HR: Ispisuje kratku pomoć.
     * EN: Prints concise help.
     */
    public function help(): int
    {
        $this->write('hph api <install|webhooks:worker|webhooks:status|help>');
        $this->write('  vendor/bin/hph api install');
        $this->write('  vendor/bin/hph api webhooks:worker --batch-size=20');
        $this->write('  vendor/bin/hph api webhooks:worker --watch --sleep=5');
        $this->write('  vendor/bin/hph api webhooks:status');

        return 0;
    }

    /**
     * HR: Obrađuje jedan webhook batch ili kontinuirano radi kada je zadan `--watch`.
     * EN: Processes one webhook batch or keeps working when `--watch` is supplied.
     *
     * @param array<int,string> $arguments
     * @param array<string,mixed> $options
     */
    public function webhookWorker(array $arguments = [], array $options = []): int
    {
        unset($arguments);
        $worker = $this->requireWebhookWorker();
        $batchSize = $this->intOption($options, ['batch-size', 'batch'], 20, 1, 100);
        $sleepSeconds = $this->intOption($options, ['sleep'], 5, 1, 300);
        $watch = $this->boolOption($options, ['watch', 'w']);

        do {
            $summary = $worker->workBatch($batchSize);
            $this->write(sprintf(
                'processed=%d delivered=%d retried=%d failed=%d',
                $summary['processed'],
                $summary['delivered'],
                $summary['retried'],
                $summary['failed'],
            ));
            if ($watch) {
                sleep($summary['processed'] === 0 ? $sleepSeconds : 1);
            }
        } while ($watch);

        return 0;
    }

    /**
     * HR: Ispisuje broj webhook isporuka po statusu.
     * EN: Prints webhook delivery counts by status.
     */
    public function webhookStatus(): int
    {
        $status = $this->requireWebhookWorker()->status();
        $this->write(sprintf(
            'pending=%d sending=%d delivered=%d failed=%d',
            $status['pending'] ?? 0,
            $status['sending'] ?? 0,
            $status['delivered'] ?? 0,
            $status['failed'] ?? 0,
        ));

        return 0;
    }

    /**
     * HR: Vraća status greške za nepoznatu podnaredbu.
     * EN: Returns an error status for an unknown subcommand.
     */
    private function unknownSubcommand(string $subcommand): int
    {
        $this->write(sprintf(__('Nepoznata API podnaredba: %s'), $subcommand));

        return 1;
    }

    /**
     * HR: Razrješava ciljni direktorij iz opcije ili app roota.
     * EN: Resolves the target directory from an option or application root.
     *
     * @param array<string, mixed> $options
     */
    private function targetDirectory(array $options): string
    {
        $path = $this->option($options, ['path', 'p']);
        if ($path === null) {
            return rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . self::DEFAULT_MIGRATIONS_PATH;
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : rtrim($this->config->getAppRootDir(), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR
                . rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * HR: Normalizira naziv generirane migracije.
     * EN: Normalizes the generated migration name.
     *
     * @param array<int, string> $arguments
     * @param array<string, mixed> $options
     */
    private function migrationSuffix(array $arguments, array $options): string
    {
        $name = $this->option($options, ['name']) ?? trim((string)($arguments[0] ?? ''));
        $name = $name !== '' ? $name : 'install_api_module_schema';
        $name = trim((string)preg_replace('/[^a-z0-9_]+/i', '_', strtolower($name)), '_');
        if ($name === '') {
            throw new InvalidArgumentException(__('Naziv migracije ne smije biti prazan.'));
        }

        return $name;
    }

    /**
     * HR: Čita prvu nepraznu skalarnu CLI opciju.
     * EN: Reads the first non-empty scalar CLI option.
     *
     * @param array<string, mixed> $options
     * @param list<string> $keys
     */
    private function option(array $options, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $options[$key] ?? null;
            if (is_scalar($value) && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }

        return null;
    }

    /**
     * HR: Čita i ograničava numeričku CLI opciju.
     * EN: Reads and bounds a numeric CLI option.
     *
     * @param array<string,mixed> $options
     * @param list<string> $keys
     */
    private function intOption(
        array $options,
        array $keys,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        $value = $this->option($options, $keys);
        if ($value === null || !is_numeric($value)) {
            return $default;
        }

        return min($maximum, max($minimum, (int)$value));
    }

    /**
     * HR: Čita zastavicu koja može biti bool ili uobičajena CLI vrijednost.
     * EN: Reads a flag supplied as a boolean or common CLI value.
     *
     * @param array<string,mixed> $options
     * @param list<string> $keys
     */
    private function boolOption(array $options, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $options)) {
                continue;
            }

            $value = $options[$key];
            if (is_bool($value)) {
                return $value;
            }

            if (!is_scalar($value)) {
                return false;
            }

            return !in_array(strtolower(trim((string)$value)), ['', '0', 'false', 'off', 'no'], true);
        }

        return false;
    }

    /**
     * HR: Vraća worker ili jasnu pogrešku kada ga host nije registrirao.
     * EN: Returns the worker or a clear error when the host did not register it.
     */
    private function requireWebhookWorker(): WebhookOutboxWorker
    {
        if (!$this->webhookWorker instanceof WebhookOutboxWorker) {
            throw new RuntimeException(__('Webhook worker nije registriran.'));
        }

        return $this->webhookWorker;
    }

    /**
     * HR: Ispisuje jednu CLI poruku.
     * EN: Prints one CLI message.
     */
    private function write(string $message): void
    {
        echo $message . PHP_EOL;
    }
}
