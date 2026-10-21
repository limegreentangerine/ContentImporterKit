<?php

namespace Concrete\Package\ContentImporterKit\Controller\SinglePage\Dashboard\ContentImporter;

use File;
use Page;
use PageType;
use Permissions;
use PageTemplate;
use Concrete\Core\Attribute\Key\Category;
use ContentImporterKit\Import\ParseResult;
use ContentImporterKit\Import\PublishTarget;
use Concrete\Core\Attribute\Key\CollectionKey;
use ContentImporterKit\Import\ArticleFileParser;
use ContentImporterKit\Scrape\ArticleImporterFactory;
use ContentImporterKit\Import\InvalidArticleException;
use ContentImporterKit\Import\PageAlreadyExistsException;
use Concrete\Core\Page\Controller\DashboardPageController;

class RunImport extends DashboardPageController
{
    protected $helpers = [
        'form',
        'form/page_selector',
        'concrete/asset_library',
    ];

    /**
     * @throws InvalidArticleException when the file as a whole is unusable
     */
    protected function parseImportFile($file): ParseResult
    {
        return (new ArticleFileParser())->parse((string) $file->getFileContents());
    }

    protected function failImport(string $message)
    {
        $this->flash('error', $message);

        return $this->buildRedirect('/dashboard/content_importer/run_import');
    }

    public function on_start()
    {
        parent::on_start();

        $pageTypes = ['' => t('Choose...')];
        $pageTemplates = ['' => t('Choose...')];

        foreach (PageType::getList(false, $this->app->make('site/type')->getDefault()) as $t) {
            $pageTypes[$t->getPageTypeHandle()] = $t->getPageTypeName();
        }

        foreach (PageTemplate::getList() as $t) {
            $pageTemplates[$t->getPageTemplateHandle()] = $t->getPageTemplateName();
        }

        $this->set('pageTypes', $pageTypes);
        $this->set('pageTemplates', $pageTemplates);
    }

    public function view()
    {
        $pageTypes = ['' => t('Choose...')];
        $pageTemplates = ['' => t('Choose...')];

        $types = PageType::getList(false, $this->app->make('site/type')->getDefault());
        $templates = PageTemplate::getList();

        foreach ($types as $t) {
            $pageTypes[$t->getPageTypeHandle()] = $t->getPageTypeName();
        }

        foreach ($templates as $t) {
            $pageTemplates[$t->getPageTemplateHandle()] = $t->getPageTemplateName();
        }

        $this->set('pageTypes', $pageTypes);
        $this->set('pageTemplates', $pageTemplates);
    }

    public function stageTwo()
    {
        if (!isset($_GET['fID'])) {
            $this->flash('error', t('Please select a file to import.'));
            $this->buildRedirect('/dashboard/content_importer/run_import')->send();
        }

        if (!isset($_GET['pageCID'])) {
            $this->flash('error', t('Please select a page.'));
            $this->buildRedirect('/dashboard/content_importer/run_import')->send();
        }

        $file = File::getByID($_GET['fID']);
        if (!is_object($file) || $file->isError()) {
            $this->flash('error', t('File not found.'));
            $this->buildRedirect('/dashboard/content_importer/run_import')->send();
        }

        $page = Page::getByID($_GET['pageCID']);
        if ($page->isError()) {
            $this->flash('error', t('Page not found.'));
            $this->buildRedirect('/dashboard/content_importer/run_import')->send();
        }

        $pageType = PageType::getByHandle($_GET['pageType']);
        if (!is_object($pageType)) {
            $this->flash('error', t('Page Type not found.'));
            $this->buildRedirect('/dashboard/content_importer/run_import')->send();
        }

        $pageTemplate = PageTemplate::getByHandle($_GET['pageTemplate']);
        if (!is_object($pageTemplate)) {
            $this->flash('error', t('Page Template not found.'));
            $this->buildRedirect('/dashboard/content_importer/run_import')->send();
        }

        $attributeCategoryEntity = Category::getByHandle('collection');
        if (!is_object($attributeCategoryEntity)) {
            $this->flash('error', t('Attribute Category not found.'));
            $this->buildRedirect('/dashboard/content_importer/run_import')->send();
        }

        $attributeHandles = [
            '' => t('Ignore'),
        ];
        $category = $attributeCategoryEntity->getAttributeKeyCategory();
        foreach ($category->getList() as $pageKey) {
            $attributeHandles[$pageKey->getAttributeKeyHandle()] = $pageKey->getAttributeKeyName();
        }

        try {
            $result = $this->parseImportFile($file);
        } catch (InvalidArticleException $e) {
            return $this->failImport($e->getMessage());
        }
        if ($result->records === []) {
            return $this->failImport(t('The file does not contain any valid articles.'));
        }

        $this->set('file', $file);
        $this->set('page', $page);
        $this->set('pageType', $pageType);
        $this->set('pageTemplate', $pageTemplate);
        $this->set('attributeHandles', $attributeHandles);
        $this->set('fileKeys', $result->attributeKeys());
        $this->set('parseErrors', $result->errors);
    }

    public function import()
    {
        if (!$this->post()) {
            return $this->buildRedirect('/dashboard/content_importer/run_import');
        }
        if (!$this->token->validate('content_importer_import')) {
            return $this->failImport($this->token->getErrorMessage());
        }

        set_time_limit(0);
        ini_set('memory_limit', '256M');
        $post = $this->post();

        $parentPage = Page::getByID((int) ($post['pageCID'] ?? 0));
        if ($parentPage->isError()) {
            return $this->failImport(t('Page not found.'));
        }

        $pageType = PageType::getByHandle((string) ($post['pageTypeHandle'] ?? ''));
        if (!is_object($pageType)) {
            return $this->failImport(t('Page Type not found.'));
        }

        $pageTemplate = PageTemplate::getByHandle((string) ($post['pageTemplateHandle'] ?? ''));
        if (!is_object($pageTemplate)) {
            return $this->failImport(t('Page Template not found.'));
        }

        $importFile = File::getByID((int) ($post['fID'] ?? 0));
        if (!is_object($importFile) || $importFile->isError()) {
            return $this->failImport(t('File not found.'));
        }

        $areaName = trim((string) ($post['areaName'] ?? ''));
        if (preg_match('/^[A-Za-z0-9 _-]{1,64}$/', $areaName) !== 1) {
            return $this->failImport(t('Please enter a valid area name.'));
        }

        // Checked once, before anything is created. The publisher does not check permissions, because scheduled
        // imports run with nobody signed in; this page is where a person is trusted to import.
        if (!(new Permissions($parentPage))->canAddSubCollection($pageType)) {
            return $this->failImport(t('You do not have permission to add pages of this type here.'));
        }

        try {
            $result = $this->parseImportFile($importFile);
        } catch (InvalidArticleException $e) {
            return $this->failImport($e->getMessage());
        }

        // Only attribute keys that exist in this file can be mapped, to attributes that exist, whatever was posted.
        $fileKeys = array_flip($result->attributeKeys());
        $attributeMap = [];
        $submittedKeys = (array) ($post['attributeAssociation']['fileKeys'] ?? []);
        $submittedHandles = (array) ($post['attributeAssociation']['attributeHandles'] ?? []);
        foreach ($submittedKeys as $id => $source) {
            $destination = $submittedHandles[$id] ?? '';
            if (!is_string($source) || !is_string($destination) || $destination === '' || !isset($fileKeys[$source])) {
                continue;
            }
            if (is_object(CollectionKey::getByHandle($destination))) {
                $attributeMap[$source] = $destination;
            }
        }

        $factory = new ArticleImporterFactory($this->app);
        $logger = $factory->logger();
        $sanitiser = $factory->sanitiser();
        $publisher = $factory->publisher();
        $target = new PublishTarget(
            (int) $parentPage->getCollectionID(),
            (string) $post['pageTypeHandle'],
            (string) $post['pageTemplateHandle'],
            $areaName,
            $attributeMap,
        );

        $created = 0;
        $existing = 0;
        $failed = 0;

        foreach ($result->records as $record) {
            try {
                $publisher->publish($sanitiser->sanitiseRecord($record), $target, null);
                ++$created;
            } catch (PageAlreadyExistsException) {
                ++$existing;
            } catch (\Throwable $e) {
                ++$failed;
                $logger->warning(sprintf('Import of "%s" failed: %s', $record->urlSlug, $e->getMessage()));
            }
        }

        $this->flash(
            $failed > 0 ? 'error' : 'success',
            t('%1$s imported, %2$s already existed, %3$s failed, %4$s invalid in the file.', $created, $existing, $failed, count($result->errors)),
        );

        return $this->buildRedirect('/dashboard/content_importer/run_import');
    }
}
