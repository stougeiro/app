<?php declare(strict_types=1);

namespace Sourcerer\Tool\Code\Verify;

use RuntimeException;
use Sourcerer\Tool\Contract\OutputInterface;
use Sourcerer\Tool\Code\Shell;
use Sourcerer\Tool\Code\Target;


final class Verifier
{
    public function __construct(
        private readonly Target $target,
        private readonly Shell $shell,
        private readonly OutputInterface $output,
    ) {
    }


    public function verify(): void
    {
        $target = $this->target;
        $root = $this->httpStatus($target->url);

        if ($root !== '200')
        {
            throw new RuntimeException('verify: GET '. $target->url .' returned '. $root);
        }

        $body = $this->shell->mustRun(
            ['curl', '-sS', '-L', '--max-time', '20', $target->url],
        )->stdout;

        if (preg_match('/Fatal error|Uncaught|Fallback::emit/i', $body) === 1)
        {
            throw new RuntimeException('verify: response contains a fatal error');
        }

        $missing = rtrim($target->url, '/') .'/missing';

        if ($this->httpStatus($missing) !== '404')
        {
            throw new RuntimeException('verify: GET '. $missing .' did not return 404');
        }

        $this->out('verify: 200 at / and 404 at /missing');
    }


    public function assertRedirect(string $url, string $expected): void
    {
        $result = $this->shell->run([
            'curl', '-sS', '-o', '/dev/null', '-w', '%{http_code} %{redirect_url}',
            '--max-time', '20', $url,
        ]);

        $parts = preg_split('/\s+/', trim($result->output())) ?: [];
        $status = $parts[0] ?? '000';
        $location = $parts[1] ?? '';

        if ($status !== '302' || rtrim($location, '/') !== rtrim($expected, '/')) {
            throw new RuntimeException(
                "retire: expected 302 to {$expected}, got {$status} {$location}",
            );
        }

        $this->out('retire verify: 302 to '. $expected);
    }


    private function httpStatus(string $url): string
    {
        $result = $this->shell->run(
            ['curl', '-sS', '-o', '/dev/null', '-w', '%{http_code}', '-L', '--max-time', '20', $url],
        );

        return $result->ok() ? $result->output() : '000';
    }


    private function out(string $message): void
    {
        $this->output->line('['. $this->target->name .'] '. $message);
    }
}
