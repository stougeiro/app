<?php declare(strict_types=1);

use App\Error\Renderer\ViewErrorRenderer;


return
[
    'renderer' => ViewErrorRenderer::class,

    'mode' => 'html', // html | json

    'messages' => [
        404 => [
            'title' => 'Página não encontrada',
            'message' => 'O recurso solicitado não existe.',
        ],
        500 => [
            'title' => 'Erro na aplicação',
            'message' => 'Ocorreu um erro inesperado.',
        ],
    ],

    'snippet' => [
        'pad' => 10,
    ],

    'log' => [
        'redact' => [ ],
    ],
];
