<?php

namespace Claroline\PluginInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Autoload\ClassLoader;
use Symfony\Component\HttpKernel\KernelInterface;
use Doctrine\Common\Annotations\AnnotationRegistry;
use Claroline\BundleRecorder\Recorder;
use Claroline\BundleRecorder\Detector;
use Claroline\InstallationBundle\Bundle\BundleVersion;

/**
 * Composer custom installer for Claroline plugin bundles.
 */
class Installer extends LibraryInstaller
{
    /**
     * @var \Symfony\Component\HttpKernel\KernelInterface
     */
    private $kernel;

    /**
     * Constructor.
     *
     * @param \Composer\IO\IOInterface                      $io
     * @param \Composer\Composer                            $composer
     * @param string                                        $type
     * @param \Symfony\Component\HttpKernel\KernelInterface $kernel
     */
    public function __construct(
        IOInterface $io,
        Composer $composer,
        $type = 'library',
        KernelInterface $kernel = null
    )
    {
        parent::__construct($io, $composer, $type);
        $this->kernel = $kernel;
    }

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
        $this->installPackage($repo, $package);
        $bundle = $this->getBundle($package->getName());
        $installer = $this->getPluginInstaller();

        try {
            $this->io->write("  - Installing <info>{$package->getName()}</info> as a Claroline plugin");
            $installer->install($bundle);
        } catch (\Exception $ex) {
            $this->getBundleRecorder()->removeBundles(array(get_class($bundle)));
            $this->uninstallPackage($repo, $package);
            $this->io->write(
                "\n<error>The following exception has been thrown during {$package->getName()} installation. "
                . "The package has been removed. Installation is aborting.</error>"
            );
            throw $ex;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        $this->io->write("  - Uninstalling Claroline plugin <info>{$package->getName()}</info>");
        $bundle = $this->getBundle($package->getName());
        $this->getPluginInstaller()->uninstall($bundle);
        $this->uninstallPackage($repo, $package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $baseInstaller = $this->getPluginInstaller();
        $bundle = $this->getBundle($initial->getName());
        $initialVersion = new BundleVersion(
            $initial->getVersion(),
            $initial->getPrettyVersion(),
            $this->getDatabaseVersion($initial)
        );
        $targetVersion = new BundleVersion(
            $target->getVersion(),
            $target->getPrettyVersion(),
            $this->getDatabaseVersion($target)
        );
        $msg = "  - Migrating <info>{$initial->getName()}</info> to version '{$target->getPrettyVersion()}'";

        // versions can be equal if a package is referred to using versions
        // like "dev-master": in that case we can't know (or can we ?) what's
        // the direction of the update (upgrade/downgrade), so the up direction
        // is chosen, as it's the more likely update move.
        if (version_compare($initial->getVersion(), $target->getVersion(), '<=')) {
            $this->updatePackage($repo, $initial, $target);
            $this->io->write($msg);
            $baseInstaller->update($bundle, $initialVersion, $targetVersion);
        } else {
            $this->io->write($msg);
            $baseInstaller->update($bundle, $initialVersion, $targetVersion);
            $this->updatePackage($repo, $initial, $target);
        }
    }

    /**
     * Parent method wrapper (testing purposes).
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo
     * @param \Composer\Package\PackageInterface                $package
     */
    public function installPackage(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);
    }

    /**
     * Parent method wrapper (testing purposes).
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo
     * @param \Composer\Package\PackageInterface                $package
     */
    public function uninstallPackage(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);
    }

    /**
     * Parent method wrapper (testing purposes).
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo
     * @param \Composer\Package\PackageInterface                $initial
     * @param \Composer\Package\PackageInterface                $target
     */
    public function updatePackage(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);
    }

    private function getBundle($packageName)
    {
        $detector = new Detector();
        $bundleClass = $detector->detectBundle("{$this->getVendorPath()}/{$packageName}");

        return new $bundleClass;
    }

    private function getPluginInstaller()
    {
        if ($this->kernel === null) {
            require_once $this->vendorDir . '/../app/AppKernel.php';
            $this->kernel = new \AppKernel('tmp' . time(), false);
            $this->kernel->boot();
        }

        $installer = $this->kernel->getContainer()->get('claroline.plugin.installer');
        $io = $this->io;
        $installer->setLogger(function ($message) use ($io) {
            $io->write("    {$message}");
        });

        return $installer;
    }

    private function getBundleRecorder()
    {
        $bundleFile = $this->getVendorPath() . '/../app/config/bundles.ini';
        $recorder = new Recorder($bundleFile);
        $io = $this->io;
        $recorder->setLogger(function ($message) use ($io) {
            $io->write("    {$message}");
        });

        return $recorder;
    }

    private function getVendorPath()
    {
        $vendorDir = rtrim($this->composer->getConfig()->get('vendor-dir'), '/');

        return realpath($vendorDir ?: '');
    }

    private function getDatabaseVersion(PackageInterface $package)
    {
        $extra = $package->getExtra();

        return isset($extra['dbVersion']) ? $extra['dbVersion'] : false;
    }
}
