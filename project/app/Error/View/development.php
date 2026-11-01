<?php declare(strict_types=1);
/** @var STDW\View\Spec\TemplateContext $this */
/** @var string $id */
/** @var string $type */
/** @var string $message */
/** @var string $file */
/** @var int $line */
/** @var string $trace */
/** @var array<int, array{num: int, code: string, hit: bool}> $snippet */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Erro na aplicação</title>
    </head>
    <body>
        <main>
            <h1>Erro na aplicação</h1>

            <p><strong>Tipo:</strong> <?= $this->echo($type) ?></p>
            <p><strong>Mensagem:</strong> <?= $this->echo($message) ?></p>
            <p><strong>Local:</strong> <?= $this->echo($file . ':' . $line) ?></p>
            <p><strong>Código:</strong> <?= $this->echo($id) ?></p>

            <?php
                $lines = [];

                foreach ($snippet as $row) {
                    $lines[] = ($row['hit'] ? '>' : ' ') . ' ' . $row['num'] . ' | ' . $row['code'];
                }
            ?>
            <?php if ($lines !== []): ?>
                <h2>Trecho</h2>
                <pre><?= $this->echo(implode("\n", $lines)) ?></pre>
            <?php endif; ?>

            <h2>Stack trace</h2>
            <pre><?= $this->echo($trace) ?></pre>
        </main>
    </body>
</html>
