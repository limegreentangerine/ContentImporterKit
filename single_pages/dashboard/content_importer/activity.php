<?php defined('C5_EXECUTE') or die('Access Denied.');

/** @var \Concrete\Core\Form\Service\Form $form */

$statuses = [
    '' => t('Any result'),
    'imported' => t('Imported'),
    'updated' => t('Updated'),
    'unchanged' => t('Unchanged'),
    'failed' => t('Failed'),
];
?>

<form method="get" action="<?php echo h((string) \URL::to('/dashboard/content_importer/activity')); ?>" class="form-inline" style="margin-bottom: 20px">
    <div class="form-group">
        <?php echo $form->select('source', $sources, $sourceID); ?>
    </div>
    <div class="form-group">
        <?php echo $form->select('status', $statuses, $status); ?>
    </div>
    <button type="submit" class="btn btn-default"><?php echo t('Filter'); ?></button>
</form>

<?php if (count($items) === 0) { ?>
    <p><?php echo t('Nothing has been imported yet.'); ?></p>
<?php } else { ?>
    <p class="help-block"><?php echo t('The %s most recent articles, newest first.', (int) $limit); ?></p>
    <table class="table table-striped">
        <thead>
            <tr>
                <th><?php echo t('When'); ?></th>
                <th><?php echo t('Source'); ?></th>
                <th><?php echo t('Article'); ?></th>
                <th><?php echo t('Result'); ?></th>
                <th><?php echo t('Page'); ?></th>
                <th><?php echo t('Problem'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($items as $item) { ?>
                <tr>
                    <td><?php echo h($item->getDateUpdatedString('Y-m-d H:i')); ?></td>
                    <td><?php echo h($item->getSource()->getName()); ?></td>
                    <td><code><?php echo h($item->getSlug()); ?></code></td>
                    <td><?php echo h($statuses[$item->getStatus()] ?? $item->getStatus()); ?></td>
                    <td>
                        <?php if ($item->getPageID() !== null) { ?>
                            <a href="<?php echo h((string) \URL::to('/index.php') . '?cID=' . (int) $item->getPageID()); ?>" target="_blank" rel="noopener"><?php echo (int) $item->getPageID(); ?></a>
                        <?php } ?>
                    </td>
                    <td><?php echo h((string) $item->getError()); ?></td>
                </tr>
            <?php } ?>
        </tbody>
    </table>
<?php } ?>
