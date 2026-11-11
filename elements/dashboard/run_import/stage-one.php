<form method="get" action="<?php echo $view->action('stageTwo'); ?>">
    <?php echo $token->output('submit') ?>

    <fieldset>
        <div class="form-group">
            <?php
                echo $form->label('fID', t('Import File'));
echo $al->file('fID', 'fID', t('Choose file'));
?>
        </div>
    </fieldset>

    <fieldset>
        <legend><?php echo t('Parent Page for Import'); ?></legend>

        <div class="form-group">
            <?php
    echo $form->label('pageCID', t('Choose page'));
echo $ps->selectPage('pageCID', $pageCID ?? null);
?>
        </div>
    </fieldset>

    <fieldset>
        <legend><?php echo t('Page Type'); ?></legend>

        <div class="form-group">
            <?php
    echo $form->label('pageType', t('Choose page type'));
echo $form->select('pageType', $pageTypes ?? null);
?>
        </div>
    </fieldset>

    <fieldset>
        <legend><?php echo t('Page Template'); ?></legend>

        <div class="form-group">
            <?php
    echo $form->label('pageTemplate', t('Choose page template'));
echo $form->select('pageTemplate', $pageTemplates ?? null);
?>
        </div>
    </fieldset>

    <div class="ccm-dashboard-form-actions-wrapper">
        <div class="ccm-dashboard-form-actions">
            <?php echo $form->submit('save', t('Next'), ['class' => 'btn btn-primary pull-right']); ?>
        </div>
    </div>
</form>