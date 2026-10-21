<?php defined('C5_EXECUTE') or die('Access Denied.');

/** @var \Concrete\Core\Form\Service\Form $form */
/** @var \Concrete\Core\Validation\CSRF\Token $token */
/** @var \Concrete\Core\Form\Service\Widget\PageSelector $form_page_selector */

if (isset($source)) {
    $saveAction = $source->isPersisted() ? $view->action('save', $source->getID()) : $view->action('save');
    ?>
    <form method="post" action="<?php echo h($saveAction); ?>">
        <?php echo $token->output('content_importer_save_source'); ?>

        <fieldset>
            <legend><?php echo t('Source'); ?></legend>

            <div class="form-group">
                <?php echo $form->label('name', t('Name')); ?>
                <?php echo $form->text('name', $source->getName(), ['maxlength' => 255]); ?>
                <div class="help-block"><?php echo t('Shown in this dashboard only.'); ?></div>
            </div>

            <div class="form-group">
                <?php echo $form->label('newsUrl', t('News URL')); ?>
                <?php echo $form->text('newsUrl', $source->getNewsUrl(), ['maxlength' => 1024, 'placeholder' => 'https://example.org/news/']); ?>
                <div class="help-block"><?php echo t('Only articles whose address starts with this are imported.'); ?></div>
            </div>

            <div class="form-group">
                <?php echo $form->label('sitemapUrl', t('Sitemap URL')); ?>
                <?php echo $form->text('sitemapUrl', $source->getSitemapUrl(), ['maxlength' => 1024, 'placeholder' => 'https://example.org/news/post-sitemap.xml']); ?>
                <div class="help-block"><?php echo t('A sitemap on the same site that lists the articles (not a sitemap index).'); ?></div>
            </div>
        </fieldset>

        <fieldset>
            <legend><?php echo t('Reading the articles'); ?></legend>
            <p class="help-block"><?php echo t('CSS selectors: tag names, .class, #id, [attribute] and [attribute=value], descendant (space) and child (&gt;) combinators, and comma-separated lists. Anything else is refused.'); ?></p>

            <div class="form-group">
                <?php echo $form->label('titleSelector', t('Title selector')); ?>
                <?php echo $form->text('titleSelector', $source->getTitleSelector(), ['maxlength' => 500, 'placeholder' => 'h1']); ?>
            </div>

            <div class="form-group">
                <?php echo $form->label('articleSelector', t('Article content selector')); ?>
                <?php echo $form->text('articleSelector', $source->getArticleSelector(), ['maxlength' => 500, 'placeholder' => '.post .content']); ?>
            </div>

            <div class="form-group">
                <?php echo $form->label('bannerSelector', t('Banner image selector (optional)')); ?>
                <?php echo $form->text('bannerSelector', (string) $source->getBannerSelector(), ['maxlength' => 500]); ?>
                <div class="help-block"><?php echo t('The first image inside it is the banner. Without it, the first image in the article is used.'); ?></div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <?php echo $form->label('sinceDays', t('Only articles changed in the last N days (optional)')); ?>
                        <?php echo $form->number('sinceDays', $source->getSinceDays() ?? '', ['min' => 1, 'max' => 3650]); ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <?php echo $form->label('maxPerRun', t('Most articles to import per run (optional)')); ?>
                        <?php echo $form->number('maxPerRun', $source->getMaxPerRun() ?? '', ['min' => 1, 'max' => 1000]); ?>
                        <div class="help-block"><?php echo t('The newest come first. Useful for the first import of a large archive: the rest follow on later runs.'); ?></div>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <?php echo $form->label('excludedSlugs', t('Ignore these pages (optional)')); ?>
                <?php echo $form->textarea('excludedSlugs', (string) $source->getExcludedSlugs(), ['rows' => 4, 'placeholder' => "blog\ncategory-*"]); ?>
                <div class="help-block"><?php echo t('A sitemap often lists pages that are not articles, such as a news index or a category. Give the last part of their address, one per line. * matches any run of letters, numbers and hyphens, for example category-*.'); ?></div>
            </div>
        </fieldset>

        <fieldset>
            <legend><?php echo t('Creating the pages'); ?></legend>

            <div class="form-group">
                <?php echo $form->label('parentPageID', t('Create the articles under')); ?>
                <?php echo $form_page_selector->selectPage('parentPageID', $source->getParentPageID() > 0 ? $source->getParentPageID() : null); ?>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="form-group">
                        <?php echo $form->label('pageTypeHandle', t('Page type')); ?>
                        <?php echo $form->select('pageTypeHandle', $pageTypes, $source->getPageTypeHandle()); ?>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-group">
                        <?php echo $form->label('pageTemplateHandle', t('Page template')); ?>
                        <?php echo $form->select('pageTemplateHandle', $pageTemplates, $source->getPageTemplateHandle()); ?>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <?php echo $form->label('areaName', t('Area for the article content')); ?>
                <?php echo $form->text('areaName', $source->getAreaName(), ['maxlength' => 64, 'placeholder' => 'Main']); ?>
                <div class="help-block"><?php echo t('The name of the area in the page template. When an article changes, the content blocks in this area are replaced; other blocks added by editors are left alone.'); ?></div>
            </div>

            <div class="form-group">
                <?php echo $form->label('bannerAttribute', t('Banner image attribute')); ?>
                <?php echo $form->select('bannerAttribute', $bannerAttributes, (string) $source->getBannerAttribute()); ?>
            </div>

            <div class="checkbox">
                <label>
                    <?php echo $form->checkbox('enabled', 1, $source->getEnabled() === 1); ?>
                    <?php echo t('Import this source when the scheduled task runs'); ?>
                </label>
            </div>
        </fieldset>

        <div class="ccm-dashboard-form-actions-wrapper">
            <div class="ccm-dashboard-form-actions">
                <a href="<?php echo h((string) \URL::to('/dashboard/content_importer/sources')); ?>" class="btn btn-default pull-left"><?php echo t('Cancel'); ?></a>
                <?php echo $form->submit('save', t('Save Source'), ['class' => 'btn btn-primary pull-right']); ?>
            </div>
        </div>
    </form>
    <?php
    return;
}
?>

<div class="ccm-dashboard-header-buttons">
    <a href="<?php echo h($view->action('add')); ?>" class="btn btn-primary"><?php echo t('Add Source'); ?></a>
</div>

<p class="help-block">
    <?php echo t('Sources are imported by the "Import News Sources" task. Run it, or schedule it, from Dashboard > System &amp; Settings > Automation.'); ?>
</p>

<?php if (count($sources) === 0) { ?>
    <p><?php echo t('No sources yet. Add one to start importing articles from a site.'); ?></p>
<?php } else { ?>
    <table class="table table-striped">
        <thead>
            <tr>
                <th><?php echo t('Name'); ?></th>
                <th><?php echo t('Sitemap'); ?></th>
                <th><?php echo t('Scheduled'); ?></th>
                <th><?php echo t('Imported'); ?></th>
                <th><?php echo t('Updated'); ?></th>
                <th><?php echo t('Unchanged'); ?></th>
                <th><?php echo t('Failed'); ?></th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sources as $item) {
                $itemCounts = $counts[$item->getID()] ?? [];
                ?>
                <tr>
                    <td><?php echo h($item->getName()); ?></td>
                    <td><code><?php echo h($item->getSitemapUrl()); ?></code></td>
                    <td><?php echo $item->getEnabled() === 1 ? t('Yes') : t('No'); ?></td>
                    <td><?php echo (int) ($itemCounts['imported'] ?? 0); ?></td>
                    <td><?php echo (int) ($itemCounts['updated'] ?? 0); ?></td>
                    <td><?php echo (int) ($itemCounts['unchanged'] ?? 0); ?></td>
                    <td>
                        <?php if (($itemCounts['failed'] ?? 0) > 0) { ?>
                            <a href="<?php echo h((string) \URL::to('/dashboard/content_importer/activity') . '?source=' . $item->getID() . '&status=failed'); ?>"><?php echo (int) $itemCounts['failed']; ?></a>
                        <?php } else { ?>
                            0
                        <?php } ?>
                    </td>
                    <td class="text-right">
                        <a href="<?php echo h($view->action('edit', $item->getID())); ?>" class="btn btn-default btn-sm"><?php echo t('Edit'); ?></a>
                        <form method="post" action="<?php echo h($view->action('delete', $item->getID())); ?>" style="display: inline-block" onsubmit="return confirm('<?php echo h(t('Delete this source and its history? The pages it created are kept.')); ?>')">
                            <?php echo $token->output('content_importer_delete_source'); ?>
                            <button type="submit" class="btn btn-danger btn-sm"><?php echo t('Delete'); ?></button>
                        </form>
                    </td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
<?php } ?>
