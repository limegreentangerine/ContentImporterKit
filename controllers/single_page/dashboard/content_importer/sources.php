<?php

namespace Concrete\Package\ContentImporterKit\Controller\SinglePage\Dashboard\ContentImporter;

use Page;
use PageType;
use Permissions;
use PageTemplate;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Concrete\Core\Attribute\Key\Category;
use ContentImporterKit\Entity\ImportedItem;
use ContentImporterKit\Entity\ImportSource;
use ContentImporterKit\Scrape\FetchException;
use ContentImporterKit\Scrape\ScrapeException;
use ContentImporterKit\Scrape\InvalidSourceException;
use ContentImporterKit\Scrape\ArticleImporterFactory;
use Concrete\Core\Page\Controller\DashboardPageController;

class Sources extends DashboardPageController
{
    protected $helpers = [
        'form',
        'form/page_selector',
    ];

    protected function entityManager(): EntityManagerInterface
    {
        return $this->app->make(EntityManagerInterface::class);
    }

    /**
     * @return array<int, array<string, int>> source ID => status => number of articles
     */
    protected function statusCounts(): array
    {
        $rows = $this->entityManager()->createQuery(
            'SELECT IDENTITY(i.source) AS sourceID, i.status AS status, COUNT(i.id) AS total FROM ' . ImportedItem::class . ' i GROUP BY i.source, i.status',
        )->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['sourceID']][(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    protected function fillFromPost(ImportSource $source): void
    {
        $post = $this->request->request;
        $text = static fn(string $key): string => trim((string) $post->get($key, ''));
        $number = static fn(string $key): ?int => trim((string) $post->get($key, '')) === '' ? null : (int) $post->get($key);

        $banner = $text('bannerSelector');
        $bannerAttribute = $text('bannerAttribute');

        $source
            ->setName($text('name'))
            ->setNewsUrl($text('newsUrl'))
            ->setSitemapUrl($text('sitemapUrl'))
            ->setTitleSelector($text('titleSelector'))
            ->setArticleSelector($text('articleSelector'))
            ->setBannerSelector($banner === '' ? null : $banner)
            ->setSinceDays($number('sinceDays'))
            ->setMaxPerRun($number('maxPerRun'))
            ->setExcludedSlugs($text('excludedSlugs') === '' ? null : trim((string) $post->get('excludedSlugs')))
            ->setParentPageID((int) $post->get('parentPageID', 0))
            ->setPageTypeHandle($text('pageTypeHandle'))
            ->setPageTemplateHandle($text('pageTemplateHandle'))
            ->setAreaName($text('areaName'))
            ->setBannerAttribute($bannerAttribute === '' ? null : $bannerAttribute)
            ->setEnabled($post->get('enabled') ? 1 : 0);
    }

    protected function validate(ImportSource $source): void
    {
        if ($source->getName() === '' || mb_strlen($source->getName()) > 255) {
            $this->error->add(t('Please give the source a name of up to 255 characters.'));
        }

        try {
            $source->toSourceConfig();
        } catch (InvalidSourceException $e) {
            $this->error->add($e->getMessage());
        }

        $parent = Page::getByID($source->getParentPageID());
        $parentExists = !$parent->isError();
        if (!$parentExists) {
            $this->error->add(t('Please choose the page the articles are created under.'));
        }
        $pageType = PageType::getByHandle($source->getPageTypeHandle());
        if (!is_object($pageType)) {
            $this->error->add(t('Please choose a page type.'));
        }

        // Scheduled runs have nobody signed in and publish without checking permissions, so the person who sets a
        // source up must be allowed to add these pages themselves. This is the same check the file import makes.
        if ($parentExists && is_object($pageType) && !(new Permissions($parent))->canAddSubCollection($pageType)) {
            $this->error->add(t('You do not have permission to add pages of this type under that page.'));
        }
        if (!is_object(PageTemplate::getByHandle($source->getPageTemplateHandle()))) {
            $this->error->add(t('Please choose a page template.'));
        }
        if (preg_match('/^[A-Za-z0-9 _-]{1,64}$/', $source->getAreaName()) !== 1) {
            $this->error->add(t('Please enter a valid area name: up to 64 letters, numbers, spaces, hyphens or underscores.'));
        }
        if ($source->getBannerAttribute() !== null && !array_key_exists($source->getBannerAttribute(), $this->bannerAttributes())) {
            $this->error->add(t('Please choose one of the image attributes for the banner.'));
        }
    }

    protected function showForm(ImportSource $source): void
    {
        $pageTypes = ['' => t('Choose...')];
        foreach (PageType::getList(false, $this->app->make('site/type')->getDefault()) as $type) {
            $pageTypes[$type->getPageTypeHandle()] = $type->getPageTypeName();
        }
        $pageTemplates = ['' => t('Choose...')];
        foreach (PageTemplate::getList() as $template) {
            $pageTemplates[$template->getPageTemplateHandle()] = $template->getPageTemplateName();
        }

        $this->set('source', $source);
        $this->set('pageTypes', $pageTypes);
        $this->set('pageTemplates', $pageTemplates);
        $this->set('bannerAttributes', ['' => t('Do not import banner images')] + $this->bannerAttributes());
        $this->render('/dashboard/content_importer/sources');
    }

    /**
     * Page attributes that can hold an image: handle => name.
     *
     * @return array<string, string>
     */
    protected function bannerAttributes(): array
    {
        $category = Category::getByHandle('collection');
        $attributes = [];
        if (is_object($category)) {
            foreach ($category->getAttributeKeyCategory()->getList() as $key) {
                if ($key->getAttributeType()->getAttributeTypeHandle() === 'image_file') {
                    $attributes[$key->getAttributeKeyHandle()] = $key->getAttributeKeyName();
                }
            }
        }

        return $attributes;
    }

    public function view()
    {
        $this->set('sources', $this->entityManager()->getRepository(ImportSource::class)->findBy([], ['name' => 'ASC']));
        $this->set('counts', $this->statusCounts());
    }

    public function add()
    {
        $this->showForm(new ImportSource());
    }

    public function edit($id = null)
    {
        $source = $this->entityManager()->find(ImportSource::class, (int) $id);
        if (!$source instanceof ImportSource) {
            $this->flash('error', t('Source not found.'));

            return $this->buildRedirect('/dashboard/content_importer/sources');
        }

        $this->showForm($source);
    }

    public function save($id = null)
    {
        if (!$this->request->isMethod('POST')) {
            return $this->buildRedirect('/dashboard/content_importer/sources');
        }
        if (!$this->token->validate('content_importer_save_source')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect('/dashboard/content_importer/sources');
        }

        $entityManager = $this->entityManager();
        $source = (int) $id > 0 ? $entityManager->find(ImportSource::class, (int) $id) : new ImportSource();
        if (!$source instanceof ImportSource) {
            $this->flash('error', t('Source not found.'));

            return $this->buildRedirect('/dashboard/content_importer/sources');
        }

        $this->fillFromPost($source);
        $this->validate($source);

        if ($this->error->has()) {
            $this->showForm($source);

            return;
        }

        $entityManager->persist($source);
        $entityManager->flush();

        $this->flash('success', t('Source saved.'));

        return $this->buildRedirect('/dashboard/content_importer/sources');
    }

    /**
     * Shows what a run of the source would do, without changing anything. It is a POST because it fetches pages.
     */
    public function preview($id = null)
    {
        if (!$this->request->isMethod('POST') || !$this->token->validate('content_importer_preview_source')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect('/dashboard/content_importer/sources');
        }

        $source = $this->entityManager()->find(ImportSource::class, (int) $id);
        if (!$source instanceof ImportSource) {
            $this->flash('error', t('Source not found.'));

            return $this->buildRedirect('/dashboard/content_importer/sources');
        }

        set_time_limit(120);

        try {
            $report = (new ArticleImporterFactory($this->app))->preview($source)->run($source->toSourceConfig(), new DateTimeImmutable());
        } catch (FetchException|ScrapeException|InvalidSourceException $e) {
            $this->flash('error', t('The preview could not be made: %s', $e->getMessage()));

            return $this->buildRedirect('/dashboard/content_importer/sources');
        }

        $this->set('previewSource', $source);
        $this->set('previewReport', $report);
        $this->view();
    }

    public function delete($id = null)
    {
        if (!$this->request->isMethod('POST') || !$this->token->validate('content_importer_delete_source')) {
            $this->flash('error', $this->token->getErrorMessage());

            return $this->buildRedirect('/dashboard/content_importer/sources');
        }

        $entityManager = $this->entityManager();
        $source = $entityManager->find(ImportSource::class, (int) $id);
        if ($source instanceof ImportSource) {
            // The history of the source goes with it. Pages that were created stay where they are.
            $entityManager->remove($source);
            $entityManager->flush();
            $this->flash('success', t('Source deleted. The pages it created were not removed.'));
        }

        return $this->buildRedirect('/dashboard/content_importer/sources');
    }
}
