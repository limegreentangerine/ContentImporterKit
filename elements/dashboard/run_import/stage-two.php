<?php if (!empty($parseErrors)) { ?>
    <div class="alert alert-warning">
        <p><?php echo t2('%s record in the file is invalid and will be skipped:', '%s records in the file are invalid and will be skipped:', count($parseErrors)); ?></p>
        <ul>
            <?php foreach ($parseErrors as $position => $reason) { ?>
                <li><?php echo t('Record %1$s: %2$s', (int) $position + 1, h($reason)); ?></li>
            <?php } ?>
        </ul>
    </div>
<?php } ?>

<form method="post" action="<?php echo $view->action('import'); ?>">
    <?php
        echo $token->output('content_importer_import');
echo $form->hidden('pageCID', $page->getCollectionID());
echo $form->hidden('fID', $file->getFileID());
echo $form->hidden('pageTypeHandle', $pageType->getPageTypeHandle());
echo $form->hidden('pageTemplateHandle', $pageTemplate->getPageTemplateHandle());
?>

    <fieldset>
        <div class="form-group">
            <?php
            echo $form->label('areaName', t('Area Name'));
echo $form->text('areaName');
?>
            <div class="help-block"><?php echo t('This is the name of the area in the <strong>%s</strong> template you want to import blocks to.', $pageTemplate->getPageTemplateName()); ?></div>
        </div>
    </fieldset>

    <fieldset>
        <legend><?php echo t('Attribute Association'); ?></legend>

        <table class="table table-bordered table-striped">
            <thead>
                <tr>
                    <th width="50%"><?php echo t('File key'); ?></th>
                    <th width="50%"><?php echo t('Associated Attribute'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fileKeys as $id => $name) { ?>
                    <tr>
                        <td vertical-align="middle">
                            <?php
                    echo $form->hidden('attributeAssociation[fileKeys][' . $id . ']', $name);
                    echo '<code>' . h($name) . '</code>';
                    ?>
                        </td>
                        <td vertical-align="middle">
                            <?php echo $form->select('attributeAssociation[attributeHandles][' . $id . ']', $attributeHandles); ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </fieldset>

    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <a href="<?php echo \URL::to('/dashboard/content_importer/run_import'); ?>" class="btn btn-default pull-left"><?php echo t('Start Again'); ?></a>
            <?php echo $form->submit('save', t('Import Content'), ['class' => 'btn btn-primary pull-right']); ?>
        </div>
    </div>
</form>