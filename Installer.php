<?php

namespace Claroline\PluginInstaller;

use AppKernel;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;

require_once __DIR__ . '/../../../../../app/autoload.php';
require_once __DIR__ . '/../../../../../app/AppKernel.php';

class Installer extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);
        $kernel = new AppKernel('dev', true);
        $kernel->boot();
        $installer = $kernel->getContainer()->get('claroline.plugin.installer');

        $parts = explode('/', $package->getName());
        $vendor = ucfirst($parts[0]);
        $bundleParts = explode('-', $parts[1]);
        $bundle = '';

        foreach ($bundleParts as $bundlePart) {
            $bundle .= ucfirst($bundlePart);
        }

        $fqcn = "{$vendor}\\{$bundle}\\{$vendor}{$bundle}";
        $path = "{$this->vendorDir}/{$package->getName()}/{$vendor}/{$bundle}/{$vendor}{$bundle}.php";
        $installer->install($fqcn, $path);
    }

    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'claroline-plugin';
    }
}

