<?php declare(strict_types=1);
/** @var STDW\View\Spec\TemplateContext $this */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Hello World</title>
        <link rel="stylesheet" href="/assets/helloworld.css">
    </head>
    <body>
        <h1 class="message"><?= $this->echo('Hello World') ?></h1>
    </body>
</html>
