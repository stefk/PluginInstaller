<?php

namespace Claroline\PluginInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;

class Installer extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'claroline-plugin';
    }

    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);
        $coreInstaller = $this->getCoreInstaller();
        $properties = $this->resolvePackageName($package->getName());

        try {
            $this->io->write("  - Installing <info>{$package->getName()}</info> as a Claroline plugin");
            $coreInstaller->install($properties['fqcn'], $properties['path']);
        } catch (\Exception $ex) {
            parent::uninstall($repo, $package);

            throw new InstallationException(
                "An exception with message '{$ex->getMessage()}' occured during "
                    . "{$package->getName()} installation. The package has been "
                    . 'removed. Installation is aborting.'
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $coreInstaller = $this->getCoreInstaller();
        $properties = $this->resolvePackageName($package->getName());
        $this->io->write("  - Uninstalling Claroline plugin <info>{$package->getName()}</info>");
        $coreInstaller->uninstall($properties['fqcn']);
        parent::uninstall($repo, $package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $coreInstaller = $this->getCoreInstaller();
        $properties = $this->resolvePackageName($initial->getName());
        $initialDbVersion = $this->getDatabaseVersion($initial);
        $targetDbVersion = $this->getDatabaseVersion($target);

        if (false === $targetDbVersion || $initialDbVersion === $targetDbVersion) {
            parent::update($repo, $initial, $target);
        } elseif (false === $initialDbVersion || $initialDbVersion < $targetDbVersion) {
            parent::update($repo, $initial, $target);
            $this->io->write("  - Migrating <info>{$target->getName()}</info> to db version '{$targetDbVersion}'");
            $coreInstaller->migrate($properties['fqcn'], $targetDbVersion);
        } elseif ($initialDbVersion > $targetDbVersion) {
            $this->io->write("  - Migrating <info>{$target->getName()}</info> to db version '{$targetDbVersion}'");
            $coreInstaller->migrate($properties['fqcn'], $targetDbVersion);
            parent::update($repo, $initial, $target);
        }
    }

    private function getCoreInstaller()
    {
        static $installer;

        if (!isset($installer)) {
            require_once __DIR__ . '/../../../../../app/autoload.php';
            require_once __DIR__ . '/../../../../../app/AppKernel.php';

            $kernel = new \AppKernel('dev', true);
            $kernel->boot();
            $installer = $kernel->getContainer()->get('claroline.plugin.installer');
        }

        return $installer;
    }

    private function resolvePackageName($packageName)
    {
        $parts = explode('/', $packageName);
        $vendor = ucfirst($parts[0]);
        $bundleParts = explode('-', $parts[1]);
        $bundle = '';

        foreach ($bundleParts as $bundlePart) {
            $bundle .= ucfirst($bundlePart);
        }

        $fqcn = "{$vendor}\\{$bundle}\\{$vendor}{$bundle}";
        $path = "{$this->vendorDir}/{$packageName}/{$vendor}/{$bundle}/{$vendor}{$bundle}.php";

        return array('fqcn' => $fqcn, 'path' => $path);
    }

    private function getDatabaseVersion(PackageInterface $package)
    {
        $extra = $package->getExtra();

        return isset($extra['dbVersion']) ? $extra['dbVersion'] : false;
    }
}
