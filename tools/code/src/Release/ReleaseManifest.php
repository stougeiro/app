<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Release;


/**
 * @phpstan-type GitInfo array{commit: string, branch: string, dirty: bool}
 * @phpstan-type OperatorInfo array{name: string, email: ?string}
 */
final class ReleaseManifest
{
    /**
     * @param null|GitInfo $git
     * @param array{}|OperatorInfo $createdBy
     */
    public function __construct(
        public readonly string $releaseId,
        public readonly string $contentHash,
        public readonly string $version,
        public readonly string $source,
        public readonly string $createdAt,
        public readonly ?array $git = null,
        public readonly ?string $previousReleaseId = null,
        public readonly array $createdBy = [],
        public readonly ?string $artifactHash = null,
    ) {
    }


    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $git = $data['git'] ?? null;
        $createdBy = $data['createdBy'] ?? null;

        return new self(
            self::string($data, 'releaseId'),
            self::string($data, 'contentHash'),
            self::string($data, 'version'),
            self::string($data, 'source'),
            self::string($data, 'createdAt'),
            is_array($git)
                && is_string($git['commit'] ?? null)
                && is_string($git['branch'] ?? null)
                && is_bool($git['dirty'] ?? null)
                ? ['commit' => $git['commit'], 'branch' => $git['branch'], 'dirty' => $git['dirty']]
                : null,
            is_string($data['previousReleaseId'] ?? null) ? $data['previousReleaseId'] : null,
            is_array($createdBy) && is_string($createdBy['name'] ?? null)
                ? [
                    'name' => $createdBy['name'],
                    'email' => is_string($createdBy['email'] ?? null) ? $createdBy['email'] : null,
                ]
                : [],
            is_string($data['artifactHash'] ?? null) ? $data['artifactHash'] : null,
        );
    }


    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'releaseId' => $this->releaseId,
            'contentHash' => $this->contentHash,
            'version' => $this->version,
            'source' => $this->source,
            'createdAt' => $this->createdAt,
            'git' => $this->git,
            'previousReleaseId' => $this->previousReleaseId,
            'createdBy' => $this->createdBy,
            'artifactHash' => $this->artifactHash,
        ];
    }



    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if ( ! is_string($value) || $value === '') {
            throw new \RuntimeException("invalid release manifest field: {$key}");
        }

        return $value;
    }
}
