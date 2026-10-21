<?php defined('C5_EXECUTE') or die('Access Denied.');

if (in_array($controller->getTask(), ['stageTwo'])) {
    View::element(
        'dashboard/run_import/stage-two',
        [
            'token' => $token,
            'controller' => $controller,
            'form' => $form,
            'file' => $file,
            'page' => $page,
            'pageType' => $pageType,
            'pageTemplate' => $pageTemplate,
            'attributeHandles' => $attributeHandles,
            'fileKeys' => $fileKeys,
            'parseErrors' => $parseErrors,
        ],
        'content_importer_kit',
    );
} else {
    View::element(
        'dashboard/run_import/stage-one',
        [
            'token' => $token,
            'form' => $form,
            'al' => $concrete_asset_library,
            'ps' => $form_page_selector,
            'pageTypes' => $pageTypes,
            'pageTemplates' => $pageTemplates,
        ],
        'content_importer_kit',
    );
}
