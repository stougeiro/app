<?php declare(strict_types=1);
/** @var STDW\View\Spec\TemplateContext $this */
/** @var string $id */
/** @var string $type */
/** @var string $short_type */
/** @var string $message */
/** @var string $file */
/** @var int $line */
/** @var array<int, array{file: string, line: ?int, call: string}> $trace */
/** @var array<int, array{num: int, code: string, hit: bool}> $snippet */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex,nofollow">
        <title>Application Error</title>
        <style>
            :root {
                --page: #eaeaea;
                --card: #ffffff;
                --card-border: #e0e0e0;
                --text: #5d5d5d;
                --muted: #8a8a8a;
                --trace: #fdecec;
                --trace-border: #f5d6d6;
                --code: #20242d;
                --code-text: #d8d8d8;
                --hit: #8c1d1d;
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                background: var(--page);
                color: var(--text);
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            .page {
                margin: 0 auto;
                max-width: 1080px;
                padding: 32px 24px 64px;
            }

            .mode {
                margin: 0;
                font-size: 2.0rem;
                font-weight: bold;
                letter-spacing: 0px;
                text-transform: uppercase;
            }

            .id {
                margin: 0 0 30px 0;
                color: #a94442;
                font-weight: bold;
                font-size: 1rem;
            }

            .card {
                margin-top: 18px;
                padding: 24px;
                background: var(--card);
                border: 1px solid var(--card-border);
                border-radius: 7px;
            }

            .card h2 {
                margin: 0px;
                font-size: 1.75rem;
            }

            .exception-name {
                margin: 0;
                font-size: 30px;
            }

            .message {
                margin: 0 0 20px;
                font-size: 1.25rem;
            }

            .location {
                margin: 0;
                color: var(--muted);
                font-size: 1rem;
                word-break: break-word;
            }

            .trace {
                margin: 15px 0px 0px 0px;
                padding: 20px;
                background: var(--trace);
                border: 1px solid var(--trace-border);
                border-radius: 7px;
                list-style: decimal;
                word-break: break-word;
            }

            .trace li {
                margin: 0 0 0 20px;
                padding-left: 8px;
                font-size: 1rem;
                line-height: 1.5;
            }

            .trace .call {
                display: block;
                color: var(--muted);
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            }

            .snippet {
                display: block;
                overflow-x: auto;
                padding: 30px 0;
                margin: 15px 0px 0px 0px;
                border-radius: 7px;
                background: var(--code);
                color: var(--code-text);
                font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
                font-size: 0.75rem;
                line-height: 1.75;
                tab-size: 4;
            }

            .snippet-line {
                display: flex;
                align-items: flex-start;
                width: 100%;
                min-width: max-content;
                box-sizing: border-box;
                padding: 0 24px 0 16px;
                white-space: pre;
            }

            .snippet-line > span:last-child {
                flex: 0 0 auto;
            }

            .snippet-line.hit {
                background: var(--hit);
            }

            .snippet-number {
                min-width: 48px;
                padding-right: 16px;
                color: #8f97a8;
                text-align: right;
                user-select: none;
            }

            @media (max-width: 640px) {
                .page { padding: 20px 12px 40px; }
                .mode { font-size: 24px; }
                .card { padding: 18px; }
            }
        </style>
    </head>
    <body>
        <main class="page">
            <header>
                <div class="mode">Application Error</div>
                <div class="id">ID: <?= $this->echo($id) ?></div>
            </header>

            <section class="card">
                <h2 class="exception-name"><?= $this->echo($short_type) ?></h2>
                <p class="message"><?= $this->echo($message) ?></p>
                <p class="location"><?= $this->echo($file) ?> (Line: <?= $this->echo((string) $line) ?>)</p>
            </section>

            <?php if ($trace !== []): ?>
                <section class="card">
                    <h2>Stack Trace</h2>
                    <ol class="trace">
                        <?php foreach ($trace as $frame): ?>
                            <li>
                                <?php if ($frame['file'] !== ''): ?>
                                    <?= $this->echo($frame['file']) ?><?= $frame['line'] !== null ? ' (Line: '. $frame['line'] .')' : '' ?>
                                <?php else: ?>
                                    Internal call
                                <?php endif; ?>
                                <span class="call"><?= $this->echo($frame['call']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </section>
            <?php endif; ?>

            <?php if ($snippet !== []): ?>
                <section class="card">
                    <h2>Code Snippet</h2>
                    <div class="snippet">
                        <?php foreach ($snippet as $row): ?>
                            <div class="snippet-line<?= $row['hit'] ? ' hit' : '' ?>">
                                <span class="snippet-number"><?= $this->echo((string) $row['num']) ?></span>
                                <span><?= $this->echo($row['code']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endif; ?>
        </main>
    </body>
</html>
