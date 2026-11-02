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
        <style>
            html,
            body {
                height: 100%;
            }

            body {
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
                background: #efefef;
                color: #222;
                font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            }

            main {
                display: flex;
                flex-direction: column;
                align-items: center;
                padding: 24px;
                text-align: center;
            }

            .status {
                font-size: clamp(64px, 10vw, 96px);
                font-weight: 300;
                line-height: 1;
                letter-spacing: -3px;
            }

            .message {
                margin-top: 8px;
                font-size: clamp(18px, 3vw, 28px);
                font-weight: 400;
                text-transform: uppercase;
                line-height: 1.1;
                letter-spacing: 3px;
            }
        </style>
    </head>
    <body>
        <main>
            <div class="status"><?= $this->echo((string) $status) ?></div>
            <div class="message"><?= $this->echo($message) ?></div>
        </main>
    </body>
</html>
