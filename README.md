README
======

Composer [custom installer][composer_doc] for Claroline plugins. It basically
wraps the Claroline [plugin installer][core_installer], allowing to install,
uninstall or update a plugin bundle through the usual composer operations.

[composer_doc]: http://getcomposer.org/doc/articles/custom-installers.md
[core_installer]: https://github.com/claroline/Claroline/blob/master/src/core/Claroline/CoreBundle/Library/Installation/Plugin/Installer.php
[![Build Status](https://secure.travis-ci.org/claroline/PluginInstaller.png?branch=master)](http://travis-ci.org/claroline/PluginInstaller)

Installation
------------

Add the package to your composer.json:

```json
"require": {
    // ...
    "claroline/plugin-installer": "dev-master"
},
```

Requirements
------------

This installer requires a standard Symfony environment and depends especially on the
usual *app/AppKernel.php* and *app/autoload.php* files.