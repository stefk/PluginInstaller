<?php

namespace Claroline\PluginInstaller;

use Mockery as m;

class InstallerTest extends \PHPUnit_Framework_TestCase
{
    private $installer;
    private $coreInstaller;
    private $autoloader;

    protected function setUp()
    {
        m::getConfiguration()->allowMockingNonExistentMethods(false);
        m::getConfiguration()->allowMockingMethodsUnnecessarily(false);

        $io = m::mock('Composer\IO\IOInterface');
        $io->shouldReceive('write');
        $config = m::mock('Composer\Config');
        $config->shouldReceive('get');
        $composer = m::mock('Composer\Composer');
        $composer->shouldReceive('getDownloadManager');
        $composer->shouldReceive('getConfig')->andReturn($config);
        $this->coreInstaller = m::mock('Claroline\CoreBundle\Library\Installation\Plugin\Installer');
        $container = m::mock('Symfony\Component\DependencyInjection\ContainerInterface');
        $container->shouldReceive('get')->with('claroline.plugin.installer')->andReturn($this->coreInstaller);
        $kernel = m::mock('Symfony\Component\HttpKernel\KernelInterface');
        $kernel->shouldReceive('getContainer')->andReturn($container);
        $this->autoloader = m::mock('Composer\Autoload\ClassLoader');

        $this->installer = m::mock(
            'Claroline\PluginInstaller\Installer[installPackage, uninstallPackage, updatePackage]',
            array($io, $composer, 'library', $kernel, $this->autoloader)
        );
    }

    protected function tearDown()
    {
        m::close();
    }

    public function testSupports()
    {
        $this->assertTrue($this->installer->supports('claroline-plugin'));
    }

    public function testInstall()
    {
        $repo = m::mock('Composer\Repository\InstalledRepositoryInterface');
        $package = m::mock('Composer\Package\PackageInterface');
        $package->shouldReceive('getName')->andReturn('foo/bar');
        $this->autoloader->shouldReceive('add')->once()->with('Foo\Bar', array('/foo/bar'));
        $this->installer->shouldReceive('installPackage')->once()->with($repo, $package);
        $this->coreInstaller->shouldReceive('install')
            ->once()
            ->with('Foo\Bar\FooBar', '/foo/bar/Foo/Bar/FooBar.php');

        $this->installer->install($repo, $package);
    }


    /**
     * @expectedException Claroline\PluginInstaller\InstallationException
     */
    public function testInstallRemovesPackageOnException()
    {
        $repo = m::mock('Composer\Repository\InstalledRepositoryInterface');
        $package = m::mock('Composer\Package\PackageInterface');
        $package->shouldReceive('getName')->andReturn('foo/bar');
        $this->autoloader->shouldReceive('add')->once()->with('Foo\Bar', array('/foo/bar'));
        $this->installer->shouldReceive('installPackage')->once()->with($repo, $package);
        $this->coreInstaller->shouldReceive('install')
            ->once()
            ->with('Foo\Bar\FooBar', '/foo/bar/Foo/Bar/FooBar.php')
            ->andThrow('Exception');
        $this->installer->shouldReceive('uninstallPackage')->once()->with($repo, $package);

        $this->installer->install($repo, $package);
    }

    public function testUninstall()
    {
        $repo = m::mock('Composer\Repository\InstalledRepositoryInterface');
        $package = m::mock('Composer\Package\PackageInterface');
        $package->shouldReceive('getName')->andReturn('foo/bar');
        $this->installer->shouldReceive('uninstallPackage')->once()->with($repo, $package);
        $this->coreInstaller->shouldReceive('uninstall')->once()->with('Foo\Bar\FooBar');

        $this->installer->uninstall($repo, $package);
    }

    public function testUpdate()
    {
        $this->markTestSkipped('Unskipped this test when changes to core installer are merged');
        $repo = m::mock('Composer\Repository\InstalledRepositoryInterface');
        $initial = m::mock('Composer\Package\PackageInterface');
        $initial->shouldReceive('getName')->andReturn('foo/bar');
        $initial->shouldReceive('getExtra')->andReturn(array('dbVersion' => '123'));
        $target = m::mock('Composer\Package\PackageInterface');
        $target->shouldReceive('getName')->andReturn('foo/bar');
        $target->shouldReceive('getExtra')->andReturn(array('dbVersion' => '456'));
        $this->installer->shouldReceive('updatePackage')->once()->with($repo, $initial, $target);
        $this->coreInstaller->shouldReceive('migrate')->once()->with('Foo\Bar\FooBar', '456');

        $this->installer->update($repo, $initial, $target);
    }
}
