<?php

namespace Claroline\PluginInstaller;

use Composer\Installer\LibraryInstaller;
use Composer\IO\IOInterface;
use Composer\Composer;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Package\PackageInterface;
use Composer\Autoload\ClassLoader;
use Symfony\Component\HttpKernel\KernelInterface;
use Claroline\BundleRecorder\Recorder;

/**
 * Composer custom installer for Claroline core bundles.
 */
class Installer extends LibraryInstaller
{
    /**
     * @var \Symfony\Component\HttpKernel\KernelInterface
     */
    private $kernel;

    /**
     * @var \Claroline\BundleRecorder\Recorder
     */
    private $recorder;

    /**
     * Constructor.
     *
     * @param \Composer\IO\IOInterface                      $io
     * @param \Composer\Composer                            $composer
     * @param string                                        $type
     * @param \Symfony\Component\HttpKernel\KernelInterface $kernel
     * @param \Claroline\BundleRecorder\Recorder            $recorder
     */
    public function __construct(
        IOInterface $io,
        Composer $composer,
        $type = 'library',
        KernelInterface $kernel = null,
        Recorder $recorder = null
    )
    {
        parent::__construct($io, $composer, $type);
        $this->kernel = $kernel;
        $this->recorder = $recorder;
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
        $this->initApplicationKernel();
        $container = $this->kernel->getContainer();
        $validator = $container->get('claroline.plugin.validator');
        $recorder = $container->get('claroline.plugin.recorder');

        try {
            $this->io->write("  - Installing <info>{$package->getName()}</info> as a Claroline plugin");
            $errors = $validator->validate($bundle);

            if (0 !== count($errors)) {
                throw new \Exception(
                    "Plugin {$bundle->getName()} configuration is incorrect: " . var_dump($errors)
                );
            }

            $recorder->register($bundle, $validator->getPluginConfiguration());
            $bundleRecorder = $this->getBundleRecorder();
            $bundleRecorder->addBundles($bundleRecorder->detectBundles($package));
            $this->initApplicationKernel();
            $this->getBaseInstaller()->install($bundle);
        } catch (\Exception $ex) {
            $recorder->unregister($bundle);
            $this->uninstallPackage($repo, $package);
            $this->io->write(
                "<error>An exception has been thrown during {$package->getName()} installation. "
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
        $this->initApplicationKernel();
        $bundle = $this->getBundle($package->getName());
        $this->io->write("  - Uninstalling Claroline plugin <info>{$package->getName()}</info>");
        $this->kernel->getContainer()->get('claroline.plugin.recorder')->unregister($bundle);
        $this->getBaseInstaller()->uninstall($bundle);
        $this->uninstallPackage($repo, $package);
    }

    /**
     * {@inheritDoc}
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        $this->initApplicationKernel();
        $baseInstaller = $this->getBaseInstaller();
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

    private function initApplicationKernel()
    {
        if ($this->kernel === null) {
            require_once $this->vendorDir . '/../app/AppKernel.php';
            $this->kernel = new \AppKernel('dev', true);
            $this->kernel->boot();
        }
    }

    private function getBundleRecorder()
    {
        if ($this->recorder === null) {
            $this->recorder = new Recorder($this->composer);
        }

        return $this->recorder;
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

        return new $fqcn;
    }

    private function getBaseInstaller()
    {
        $baseInstaller = $this->kernel->getContainer()->get('claroline.installation.manager');
        $io = $this->io;
        $baseInstaller->setLogger(function ($message) use ($io) {
            $io->write("    {$message}");
        });

        return $baseInstaller;
    }

    private function getDatabaseVersion(PackageInterface $package)
    {
        $extra = $package->getExtra();

        return isset($extra['dbVersion']) ? $extra['dbVersion'] : false;
    }
}
