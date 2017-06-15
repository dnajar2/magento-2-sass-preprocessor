<?php

/**
 * @copyright  Copyright 2017 SplashLab
 */

namespace SplashLab\SassPreprocessor\Preprocessor\Instruction;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Css\PreProcessor\ErrorHandlerInterface;
use Magento\Framework\View\Asset\File\FallbackContext;
use Magento\Framework\View\Asset\LocalInterface;
use Magento\Framework\View\Asset\PreProcessorInterface;
use Magento\Framework\View\Design\Theme\ThemeProviderInterface;
use Magento\Framework\View\DesignInterface;
use Magento\Framework\View\File\CollectorInterface;

/**
 * Class MagentoImport
 * @package SplashLab\SassPreprocessor
 * @ magento_import instruction preprocessor
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Must be deleted after moving themeProvider to construct
 */
class MagentoImport implements PreProcessorInterface
{
    /**
     * PCRE pattern that matches @magento_import instruction
     */
    const REPLACE_PATTERN =
        '#//@magento_import(?P<reference>\s+\(reference\))?\s+[\'\"](?P<path>(?![/\\\]|\w:[/\\\])[^\"\']+)[\'\"]\s*?;#';

    /**
     * @var DesignInterface
     */
    protected $design;

    /**
     * @var CollectorInterface
     */
    protected $fileSource;

    /**
     * @var ErrorHandlerInterface
     */
    protected $errorHandler;

    /**
     * @var \Magento\Framework\View\Asset\Repository
     */
    protected $assetRepo;

    /**
     * @var \Magento\Framework\View\Design\Theme\ListInterface
     * @deprecated
     */
    protected $themeList;

    /**
     * @var ThemeProviderInterface
     */
    private $themeProvider;

    /**
     * @param DesignInterface $design
     * @param CollectorInterface $fileSource
     * @param ErrorHandlerInterface $errorHandler
     * @param \Magento\Framework\View\Asset\Repository $assetRepo
     * @param \Magento\Framework\View\Design\Theme\ListInterface $themeList
     */
    public function __construct(
        DesignInterface $design,
        CollectorInterface $fileSource,
        ErrorHandlerInterface $errorHandler,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\View\Design\Theme\ListInterface $themeList
    ) {
        $this->design = $design;
        $this->fileSource = $fileSource;
        $this->errorHandler = $errorHandler;
        $this->assetRepo = $assetRepo;
        $this->themeList = $themeList;
    }

    /**
     * {@inheritdoc}
     */
    public function process(\Magento\Framework\View\Asset\PreProcessor\Chain $chain)
    {
        $asset = $chain->getAsset();
        $contentType = $chain->getContentType();
        $replaceCallback = function ($matchContent) use ($asset, $contentType) {
            return $this->replace($matchContent, $asset, $contentType);
        };
        $chain->setContent(preg_replace_callback(self::REPLACE_PATTERN, $replaceCallback, $chain->getContent()));
    }

    /**
     * Replace @magento_import to @import instructions
     *
     * @param array $matchedContent
     * @param LocalInterface $asset
     * @return string
     */
    protected function replace(array $matchedContent, LocalInterface $asset, $contentType)
    {
        $importsContent = '';
        try {
            // add underscore to include file name
            $matchedFileId = basename($matchedContent['path']);
            if ($matchedFileId[0] != '_') {
                $matchedFileId = '_' . $matchedFileId;
            }
            $matchedFileId = dirname($matchedContent['path']) . '/' . $matchedFileId;
            $matchedFileId = $this->fixFileExtension($matchedFileId, $contentType);
            //$matchedFileId = $matchedContent['path'];
            $isReference = !empty($matchedContent['reference']);
            $relatedAsset = $this->assetRepo->createRelated($matchedFileId, $asset);
            $resolvedPath = $relatedAsset->getFilePath();
            $importFiles = $this->fileSource->getFiles($this->getTheme($relatedAsset), $resolvedPath);
            /** @var $importFile \Magento\Framework\View\File */
            foreach ($importFiles as $importFile) {
                $referenceString = $isReference ? '(reference) ' : '';
                $importsContent .= $importFile->getModule()
                    ? "@import $referenceString'{$importFile->getModule()}::{$resolvedPath}';\n"
                    : "@import $referenceString'{$matchedFileId}';\n";
            }
        } catch (\LogicException $e) {
            $this->errorHandler->processException($e);
        }
        return $importsContent;
    }

    /**
     * Resolve extension of imported asset according to exact format
     *
     * @param string $fileId
     * @param string $contentType
     * @return string
     * @link http://lesscss.org/features/#import-directives-feature-file-extensions
     */
    protected function fixFileExtension($fileId, $contentType)
    {
        if (!pathinfo($fileId, PATHINFO_EXTENSION)) {
            $fileId .= '.' . $contentType;
        }
        return $fileId;
    }

    /**
     * Get theme model based on the information from asset
     *
     * @param LocalInterface $asset
     * @return \Magento\Framework\View\Design\ThemeInterface
     */
    protected function getTheme(LocalInterface $asset)
    {
        $context = $asset->getContext();
        if ($context instanceof FallbackContext) {
            return $this->getThemeProvider()->getThemeByFullPath(
                $context->getAreaCode() . '/' . $context->getThemePath()
            );
        }
        return $this->design->getDesignTheme();
    }

    /**
     * @return ThemeProviderInterface
     * @deprecated
     */
    private function getThemeProvider()
    {
        if (null === $this->themeProvider) {
            $this->themeProvider = ObjectManager::getInstance()->get(ThemeProviderInterface::class);
        }

        return $this->themeProvider;
    }

}