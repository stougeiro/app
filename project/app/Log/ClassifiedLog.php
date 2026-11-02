<?php declare(strict_types=1);

namespace App\Log;

use Sourcerer\Contract\Log\LogInterface;
use Sourcerer\Log\Log;


final class ClassifiedLog implements LogInterface
{
    public function __construct(
        private readonly Log $errors,
        private readonly Log $security,
    ) { }


    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $context = $this->enrich($context);
        $status = $context['status'] ?? null;

        if (is_numeric($status) && (int) $status >= 400 && (int) $status < 500) {
            $this->security->warn($message, $context);
            return;
        }

        $this->errors->error($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warn(string $message, array $context = []): void
    {
        $this->errors->warn($message, $this->enrich($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->errors->info($message, $this->enrich($context));
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $message, array $context = []): void
    {
        $this->errors->debug($message, $this->enrich($context));
    }


    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function enrich(array $context): array
    {
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';

        $context['host'] ??= is_string($host) ? $host : '';
        $context['referer'] ??= is_string($referer) ? $referer : '';

        return $context;
    }
}
