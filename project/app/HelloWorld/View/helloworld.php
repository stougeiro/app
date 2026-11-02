<?php declare(strict_types=1);
/** @var STDW\View\Spec\TemplateContext $this */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Hello World</title>
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
        <h1 class="message"><?= $this->echo('Hello World') ?></h1>
    </body>
</html>
