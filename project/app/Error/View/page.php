<?php declare(strict_types=1);
/** @var STDW\View\Spec\TemplateContext $this */
/** @var int $status */
/** @var string $title */
/** @var string $message */ ?>
<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex,nofollow">
        <title><?= $this->echo((string) $status) ?> <?= $this->echo($title) ?></title>
        <link rel="stylesheet" href="/assets/error/page.css">
    </head>
    <body>
        <main>
            <div class="status"><?= $this->echo((string) $status) ?></div>
            <div class="message"><?= $this->echo($message) ?></div>
        </main>
    </body>
</html>
