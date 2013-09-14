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
        $bundle = $this->getBundle($package->getName());
        $initialDbVersion = $this->getDatabaseVersion($initial);
        $targetDbVersion = $this->getDatabaseVersion($target);

        if (false === $targetDbVersion || $initialDbVersion === $targetDbVersion) {
            $this->updatePackage($repo, $initial, $target);
        } elseif (false === $initialDbVersion || $initialDbVersion < $targetDbVersion) {
            $this->updatePackage($repo, $initial, $target);
            $this->io->write("  - Migrating <info>{$target->getName()}</info> to db version '{$targetDbVersion}'");
            $baseInstaller->migrate($bundle, $targetDbVersion);
        } elseif ($initialDbVersion > $targetDbVersion) {
            $this->io->write("  - Migrating <info>{$target->getName()}</info> to db version '{$targetDbVersion}'");
            $baseInstaller->migrate($bundle, $targetDbVersion);
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
        $parts = explode('/', $packageName);
        $vendor = ucfirst($parts[0]);
        $bundleParts = explode('-', $parts[1]);
        $bundle = '';

        foreach ($bundleParts as $bundlePart) {
            $bundle .= ucfirst($bundlePart);
        }

        $namespace = "{$vendor}\\{$bundle}";
        $fqcn = "{$namespace}\\{$vendor}{$bundle}";
        $packagePath = "{$this->vendorDir}/{$packageName}";

        $loader = new ClassLoader();
        $loader->add($namespace, $packagePath);
        $loader->register();

        if (class_exists('Doctrine\Common\Annotations\AnnotationRegistry')) {
            AnnotationRegistry::registerLoader(array($loader, 'loadClass'));
        }

        return new $fqcn;
    }

    private function getPluginInstaller()
    {
        $kernel = $this->getKernel();
        $installer = $kernel->getContainer()->get('claroline.plugin.installer');
        $io = $this->io;
        $installer->setLogger(function ($message) use ($io) {
            $io->write("    {$message}");
        });

        return $installer;
    }

    private function getKernel()
    {
        if ($this->kernel === null) {
            require_once $this->vendorDir . '/../app/AppKernel.php';
            $kernel = new \AppKernel('tmp' . time(), false);
            $kernel->boot();

            return $kernel;
        }

        return $this->kernel;
    }

    private function getBundleRecorder()
    {
        $vendorDir = rtrim($this->composer->getConfig()->get('vendor-dir'), '/');
        $bundleFile = realpath(($vendorDir ? $vendorDir . '/' : '') . '/../app/config/bundles.ini');
        $recorder = new Recorder($bundleFile);
        $io = $this->io;
        $recorder->setLogger(function ($message) use ($io) {
            $io->write("    {$message}");
        });

        return $recorder;
    }

    private function getDatabaseVersion(PackageInterface $package)
    {
        $extra = $package->getExtra();

        return isset($extra['dbVersion']) ? $extra['dbVersion'] : false;
    }
}
