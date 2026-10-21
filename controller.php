<?php

namespace Concrete\Package\ContentImporterKit;

use Concrete\Core\Entity\Package;
use ClassKit\Package\PackageController;
use ClassKit\Package\Traits\{BlockTrait, PageTrait};

class Controller extends PackageController
{
    use BlockTrait;
    use PageTrait;
    /**
     * The packages handle.
     * Note that this must be unique in the
     * entire concrete5 package ecosystem.
     *
     * @var string
     */
    protected $pkgHandle = 'content_importer_kit';

    /**
     * The packages version.
     *
     * @var string
     */
    protected $pkgVersion = '1.0.0';

    /**
     * The minimum Concrete version compatible with the package.
     * Override this value according to the minimum required version for your package.
     *
     * @var string
     */
    protected $appVersionRequired = '9.5.0';

    /**
     * The minimum PHP version compatible with the package.
     * Override this value according to the minimum required version for your package.
     *
     * @var string
     */
    protected $phpVersionRequired = '8.4';

    /**
     * Package service providers to register.
     *
     * eg. 'Concrete\Package\PackageHandle\Src\Providers\PackageServiceProvider'
     *
     * @var array
     */
    protected $providers = [];

    /**
     * An array describing the package dependencies.
     * Keys are package handles.
     * Values may be:
     * - false: this package can't be installed if the other package is already installed.
     * - true: this package can't be installed of the other package is not installed
     * - a string: this package can't be installed of the other package is not installed or it's installed with an older version
     * - an array with two strings, representing the minimum and the maximum version of the other package to be installed.
     *
     * @var array
     *
     * @example [
     *     // This package can't be installed if a package with handle other_package_1 is already installed.
     *     'other_package_1' => false,
     *     // This package can't be installed if a package with handle other_package_2 is not installed.
     *     'other_package_2' => true,
     *     // This package can't be installed if a package with handle other_package_3 is not installed, or it has a version prior to 1.0
     *     'other_package_3' => '1.0',
     *     // This package can't be installed if a package with handle other_package_4 is not installed, or it has a version prior to 2.0, or it has a version after 2.9
     *     'other_package_4' => ['2.0', '2.9'],
     * ]
     */
    protected $packageDependencies = [
        'class_kit' => true,
    ];

    /**
     * Package class autoloader registrations
     * The package install helper class, included with this boilerplate,
     * is activated by default.
     *
     * @see https://goo.gl/4wyRtH
     * @var array
     */
    protected $pkgAutoloaderRegistries = [
        'src' => '\ContentImporterKit',
    ];

    /**
     * Package tasks to register.
     *
     * eg. 'task_handle' => \PackageHandle\Command\Task\Controller\TaskHandleController::class,
     *
     * @var array
     */
    protected $tasks = [
        'import_sources' => \ContentImporterKit\Command\Task\Controller\ImportSourcesController::class,
    ];

    public function getPackageName()
    {
        return t('Content Importer');
    }

    public function getPackageDescription()
    {
        return t('Content Importer');
    }

    public function installOrUpgrade(Package $pkg)
    {
        // Add scheduled tasks
        $this->installContentFile('tasks.xml');

        // Add dashboard pages
        $this->addSinglePage('/dashboard/content_importer', $pkg, t('Content Importer'));
        $this->addSinglePage('/dashboard/content_importer/sources', $pkg, t('Sources'), t('Sites the importer scrapes'));
        $this->addSinglePage('/dashboard/content_importer/activity', $pkg, t('Activity'), t('What the importer did with each article'));
        $this->addSinglePage('/dashboard/content_importer/run_import', $pkg, t('Import From File'));

        // add json as allowed file type
        $helper_file = \Core::make('helper/concrete/file');
        $file_access_file_types = $helper_file->unserializeUploadFileExtensions(\Config::get('concrete.upload.extensions'));
        if (!in_array('json', $file_access_file_types)) {
            $file_access_file_types[] = 'json';
            $types = $helper_file->serializeUploadFileExtensions($file_access_file_types);
            \Config::save('concrete.upload.extensions', $types);
        }
    }

    public function on_start()
    {
        // A package installed by path or symlink has its own vendor folder that the site does not autoload. Only
        // HTMLPurifier's own loader is included: the package's full vendor/autoload.php also contains the
        // development copy of concrete5/core, which must never shadow the site's core.
        $purifierLoader = __DIR__ . '/vendor/ezyang/htmlpurifier/library/HTMLPurifier.auto.php';
        if (!class_exists('HTMLPurifier_Config', false) && is_file($purifierLoader)) {
            require_once $purifierLoader;
        }

        parent::on_start();
    }

    public function registerRoutes(): void {}

    public function registerEvents(): void {}
}
