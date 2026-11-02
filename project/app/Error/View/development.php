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
        <link rel="stylesheet" href="/assets/error/development.css">
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
