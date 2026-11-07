<?php declare(strict_types=1);

namespace Tool\Deploy\Event;


/**
 * @phpstan-type OperatorInfo array{name: string, email: ?string}
 */
final class DeployEvent
{
    /**
     * @param OperatorInfo $by
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly string $action,
        public readonly string $releaseId,
        public readonly string $target,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly string $at,
        public readonly array $by,
        public readonly ?string $backup = null,
        public readonly string $status = 'ok',
        public readonly array $context = [],
    ) {
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'releaseId' => $this->releaseId,
            'target' => $this->target,
            'from' => $this->from,
            'to' => $this->to,
            'at' => $this->at,
            'by' => $this->by,
            'backup' => $this->backup,
            'status' => $this->status,
            'context' => $this->context,
        ];
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $by = $data['by'] ?? [];

        return new self(
            self::string($data, 'action'),
            self::string($data, 'releaseId'),
            self::string($data, 'target'),
            is_string($data['from'] ?? null) ? $data['from'] : null,
            is_string($data['to'] ?? null) ? $data['to'] : null,
            self::string($data, 'at'),
            is_array($by) && is_string($by['name'] ?? null)
                ? [
                    'name' => $by['name'],
                    'email' => is_string($by['email'] ?? null) ? $by['email'] : null,
                ]
                : ['name' => 'unknown', 'email' => null],
            is_string($data['backup'] ?? null) ? $data['backup'] : null,
            is_string($data['status'] ?? null) ? $data['status'] : 'ok',
            is_array($data['context'] ?? null) ? $data['context'] : [],
        );
    }


    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if ( ! is_string($value) || $value === '') {
            throw new \RuntimeException("invalid deploy event field: {$key}");
        }

        return $value;
    }
}
