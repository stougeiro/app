<?php declare(strict_types=1);

use Sourcerer\Tool\Schema\Canonical\CanonicalJson;
use Sourcerer\Tool\Schema\Canonical\SchemaHasher;
use Sourcerer\Tool\Schema\Domain\Column;
use Sourcerer\Tool\Schema\Domain\SchemaState;
use Sourcerer\Tool\Schema\Domain\Table;


function canonicalColumn(string $name, int $position): Column
{
    return new Column($name, 'TEXT', true, null, $position, false);
}


function canonicalState(): SchemaState
{
    return new SchemaState(
        [
            'users' => new Table(
                'users',
                [
                    'name' => canonicalColumn('name', 1),
                    'id' => canonicalColumn('id', 0),
                ],
                ['id'],
                [],
                [],
            ),
        ],
        [],
    );
}


test('canonical json sorts associative keys recursively', function () {
    $encoded = CanonicalJson::encode([
        'b' => ['d' => 2, 'c' => 1],
        'a' => [3, 2, 1],
    ]);

    expect($encoded)->toBe('{"a":[3,2,1],"b":{"c":1,"d":2}}');
});


test('canonical json keeps empty maps as objects', function () {
    $state = new SchemaState([], []);

    expect(CanonicalJson::encode($state->toArray()))
        ->toContain('"tables":{}')
        ->toContain('"views":{}')
        ->toContain('"sequences":{}');
});


test('schema hash is stable regardless of insertion order', function () {
    $hasher = new SchemaHasher();

    expect($hasher->hash(canonicalState()))->toBe($hasher->hash(canonicalState()));
});


test('schema hash changes when structure changes', function () {
    $hasher = new SchemaHasher();
    $state = canonicalState();
    $changed = new SchemaState(
        [
            'users' => new Table(
                'users',
                [
                    'name' => canonicalColumn('name', 1),
                    'id' => new Column('id', 'INTEGER', false, null, 0, true),
                ],
                ['id'],
                [],
                [],
            ),
        ],
        [],
    );

    expect($hasher->hash($state))->not->toBe($hasher->hash($changed));
});
