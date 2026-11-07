<?php declare(strict_types=1);

use Sourcerer\Tool\Code\Maintenance;
use Sourcerer\Tool\Code\ShellResult;
use Sourcerer\Tool\Code\Ssh;
use Tests\Feature\Code\DeployFixtures;
use Tests\Feature\Code\FakeShell;


function deployMaintenanceShell(): FakeShell
{
    return new FakeShell(function (array $command): ShellResult {
        $remote = $command[count($command) - 1];

        if (str_contains($remote, 'echo active'))
        {
            return new ShellResult(0, 'active', '');
        }

        return new ShellResult(0, '', '');
    });
}


test('enables maintenance by touching the marker', function () {
    $shell = deployMaintenanceShell();
    $maintenance = new Maintenance(new Ssh($shell));

    $maintenance->enable(DeployFixtures::target());

    expect($shell->calls[0]['command'][count($shell->calls[0]['command']) - 1])
        ->toContain('touch')
        ->toContain('.maintenance');
});


test('disables maintenance by removing the marker', function () {
    $shell = deployMaintenanceShell();
    $maintenance = new Maintenance(new Ssh($shell));

    $maintenance->disable(DeployFixtures::target());

    expect($shell->calls[0]['command'][count($shell->calls[0]['command']) - 1])
        ->toContain('rm -f')
        ->toContain('.maintenance');
});


test('reports the maintenance status', function () {
    $maintenance = new Maintenance(new Ssh(deployMaintenanceShell()));

    expect($maintenance->status(DeployFixtures::target()))->toBe('active');
});
