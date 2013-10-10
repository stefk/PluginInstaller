<?php

namespace Claroline\PluginInstaller;

use Composer\Installer\LibraryInstaller;

/**
 * Composer custom installer for Claroline plugin bundles.
 */
class Installer extends LibraryInstaller
{
    /**
     * {@inheritDoc}
     */
    public function supports($packageType)
    {
        return $packageType === 'claroline-plugin';
    }
}
