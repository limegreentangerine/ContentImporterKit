<?php

namespace Concrete\Package\ContentImporterKit\Controller\SinglePage\Dashboard;

use Concrete\Core\Page\Controller\DashboardPageController;

class ContentImporter extends DashboardPageController
{
    public function view()
    {
        return $this->buildRedirect('/dashboard/content_importer/sources');
    }
}
