<?php
/**
 * Crop images via focus crop
 *
 * @package Focuspoint\Service
 * @author  Tim Lochmüller
 */

namespace HDNET\Focuspoint\Service;

use HDNET\Focuspoint\Service\WizardHandler\Group;
use HDNET\Focuspoint\Utility\GlobalUtility;
use TYPO3\CMS\Core\Imaging\GraphicalFunctions;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\FileReference as CoreFileReference;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;
use TYPO3\CMS\Extbase\Domain\Model\FileReference;
use TYPO3\CMS\Extbase\Reflection\ObjectAccess;
use TYPO3\CMS\Frontend\Imaging\GifBuilder;

/**
 * Crop images via focus crop
 *
 * @author Tim Lochmüller
 */
class FocusCropService extends AbstractService
{

    const SIGNAL_tempImageCropped = 'tempImageCropped';

    /**
     * @var \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
     */
    protected $signalSlotDispatcher;

    /**
     * get the image
     *
     * @param $src
     * @param $image
     * @param $treatIdAsReference
     *
     * @return \TYPO3\CMS\Core\Resource\File|FileInterface|CoreFileReference|\TYPO3\CMS\Core\Resource\Folder
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
     * @throws \TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException
     */
    public function getViewHelperImage($src, $image, $treatIdAsReference)
    {
        $resourceFactory = ResourceFactory::getInstance();
        if ($image instanceof FileReference) {
            $image = $image->getOriginalResource();
        }
        if ($image instanceof CoreFileReference) {
            return $image->getOriginalFile();
        }
        if (!MathUtility::canBeInterpretedAsInteger($src)) {
            return $resourceFactory->retrieveFileOrFolderObject($src);
        }
        if (!$treatIdAsReference) {
            return $resourceFactory->getFileObject($src);
        }
        $image = $resourceFactory->getFileReferenceObject($src);
        return $image->getOriginalFile();
    }

    /**
     * Helper function for view helpers
     *
     * @param $src
     * @param $image
     * @param $treatIdAsReference
     * @param $ratio
     *
     * @throws \TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException
     * @throws \TYPO3\CMS\Core\Resource\Exception\ResourceDoesNotExistException
     *
     * @return string
     */
    public function getCroppedImageSrcForViewHelper($src, $image, $treatIdAsReference, $ratio)
    {
        $file = $this->getViewHelperImage($src, $image, $treatIdAsReference);
        return $this->getCroppedImageSrcByFile($file, $ratio);
    }

    /**
     * Get the cropped image
     *
     * @param string $fileReference
     * @param string $ratio
     *
     * @return string The new filename
     */
    public function getCroppedImageSrcByFileReference($fileReference, $ratio)
    {
        if ($fileReference instanceof FileReference) {
            $fileReference = $fileReference->getOriginalResource();
        }
        if ($fileReference instanceof CoreFileReference) {
            return $this->getCroppedImageSrcByFile($fileReference->getOriginalFile(), $ratio);
        }
        throw new \InvalidArgumentException('The given argument is not a valid file reference', 123671283);
    }

    /**
     * Get the cropped image by File Object
     *
     * @param FileInterface $file
     * @param string $ratio
     *
     * @return string The new filename
     */
    public function getCroppedImageSrcByFile(FileInterface $file, $ratio)
    {
        return $this->getCroppedImageSrcBySrc(
            $file->getPublicUrl(),
            $ratio,
            $file->getProperty('focus_point_x'),
            $file->getProperty('focus_point_y')
        );
    }


    /**
     * Get the cropped image by src
     *
     * @param string $src Relative file name
     * @param string $ratio
     * @param int $x
     * @param int $y
     *
     * @return string The new filename
     */
    public function getCroppedImageSrcBySrc($src, $ratio, $x, $y)
    {
        $absoluteImageName = GeneralUtility::getFileAbsFileName($src);
        if (!is_file($absoluteImageName)) {
            return null;
        }
        $focusPointX = MathUtility::forceIntegerInRange((int)$x, -100, 100, 0);
        $focusPointY = MathUtility::forceIntegerInRange((int)$y, -100, 100, 0);

        if ($focusPointX === 0 && $focusPointY === 0) {
            $connection = GlobalUtility::getDatabaseConnection();
            $row = $connection->exec_SELECTgetSingleRow(
                'uid,focus_point_x,focus_point_y',
                Group::TABLE,
                'relative_file_path = ' . $connection->fullQuoteStr($src, Group::TABLE)
            );
            if ($row) {
                $focusPointX = MathUtility::forceIntegerInRange((int)$row['focus_point_x'], -100, 100, 0);
                $focusPointY = MathUtility::forceIntegerInRange((int)$row['focus_point_y'], -100, 100, 0);
            }
        }

        $hash = function_exists('sha1_file') ? sha1_file($absoluteImageName) : md5_file($absoluteImageName);
        $tempImageFolder = 'typo3temp/focuscrop/';
        $tempImageName = $tempImageFolder . $hash . '-fp-' . preg_replace(
            '/[^0-9]/',
            '-',
            $ratio
        ) . '-' . $focusPointX . '-' . $focusPointY . '.' . PathUtility::pathinfo(
            $absoluteImageName,
            PATHINFO_EXTENSION
        );
        $tempImageName = preg_replace('/--+/', '-', $tempImageName);
        $absoluteTempImageName = GeneralUtility::getFileAbsFileName($tempImageName);

        if (is_file($absoluteTempImageName)) {
            return $tempImageName;
        }

        $absoluteTempImageFolder = GeneralUtility::getFileAbsFileName($tempImageFolder);
        if (!is_dir($absoluteTempImageFolder)) {
            GeneralUtility::mkdir_deep($absoluteTempImageFolder);
        }

        $imageSizeInformation = getimagesize($absoluteImageName);
        $width = $imageSizeInformation[0];
        $height = $imageSizeInformation[1];

        // dimensions
        /** @var DimensionService $dimensionService */
        $dimensionService = GeneralUtility::makeInstance(DimensionService::class);
        list($focusWidth, $focusHeight) = $dimensionService->getFocusWidthAndHeight($width, $height, $ratio);
        $cropMode = $dimensionService->getCropMode($width, $height, $ratio);
        list($sourceX, $sourceY) = $dimensionService->calculateSourcePosition(
            $cropMode,
            $width,
            $height,
            $focusWidth,
            $focusHeight,
            $focusPointX,
            $focusPointY
        );

        // generate image
        if (strtolower(PathUtility::pathinfo($absoluteImageName, PATHINFO_EXTENSION)) == 'png') {
            $this->createCropImageGifBuilder(
                $absoluteImageName,
                $focusWidth,
                $focusHeight,
                $sourceX,
                $sourceY,
                $absoluteTempImageName
            );
        } else {
            $this->createCropImageGraphicalFunctions(
                $absoluteImageName,
                $focusWidth,
                $focusHeight,
                $sourceX,
                $sourceY,
                $absoluteTempImageName
            );
        }

        $this->emitTempImageCropped($tempImageName);

        return $tempImageName;
    }

    /**
     * Create the crop image (GifBuilder)
     *
     * @param string $absoluteImageName
     * @param $focusWidth
     * @param $focusHeight
     * @param integer $sourceX
     * @param integer $sourceY
     * @param string $absoluteTempImageName
     */
    protected function createCropImageGifBuilder(
        $absoluteImageName,
        $focusWidth,
        $focusHeight,
        $sourceX,
        $sourceY,
        $absoluteTempImageName
    ) {
        $size = getimagesize($absoluteImageName);
        $relativeImagePath = rtrim(PathUtility::getRelativePath(
            GeneralUtility::getIndpEnv('TYPO3_DOCUMENT_ROOT'),
            $absoluteImageName
        ), '/');
        $configuration = [
            'format' => strtolower(PathUtility::pathinfo($absoluteImageName, PATHINFO_EXTENSION)),
            'XY' => $size[0] . ',' . $size[1],
            'transparentBackground' => '1',
            '10' => 'IMAGE',
            '10.' => [
                'file' => $relativeImagePath,
                'file.' => [
                    'quality' => $GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality'],
                    'width' => $size[0],
                    'height' => $size[1],
                ],
            ],
            '20' => 'CROP',
            '20.' => [
                'crop' => $sourceX . ',' . $sourceY . ',' . $focusWidth . ',' . $focusHeight,
            ],
        ];

        /** @var GifBuilder $gifBuilder */
        $gifBuilder = GeneralUtility::makeInstance(GifBuilder::class);
        $gifBuilder->init();
        $gifBuilder->start($configuration, []);
        $gifBuilder->createTempSubDir('focuscrop/');
        $gifBuilder->make();
        $gifBuilder->output($absoluteTempImageName);
        $gifBuilder->destroy();
    }

    /**
     * Create the crop image (GraphicalFunctions)
     *
     * @param string $absoluteImageName
     * @param $focusWidth
     * @param $focusHeight
     * @param integer $sourceX
     * @param integer $sourceY
     * @param string $absoluteTempImageName
     */
    protected function createCropImageGraphicalFunctions(
        $absoluteImageName,
        $focusWidth,
        $focusHeight,
        $sourceX,
        $sourceY,
        $absoluteTempImageName
    ) {
        /** @var GraphicalFunctions $graphicalFunctions */
        $graphicalFunctions = GeneralUtility::makeInstance(GraphicalFunctions::class);
        $sourceImage = $graphicalFunctions->imageCreateFromFile($absoluteImageName);
        $destinationImage = imagecreatetruecolor($focusWidth, $focusHeight);

        // prevent the problem of large images result in a "Allowed memory size" error
        // we do not need the alpha layer at all, because the PNG rendered with createCropImageGifBuilder
        ObjectAccess::setProperty($graphicalFunctions, 'saveAlphaLayer', true, true);

        $graphicalFunctions->imagecopyresized(
            $destinationImage,
            $sourceImage,
            0,
            0,
            $sourceX,
            $sourceY,
            $focusWidth,
            $focusHeight,
            $focusWidth,
            $focusHeight
        );

        $graphicalFunctions->ImageWrite(
            $destinationImage,
            $absoluteTempImageName,
            $GLOBALS['TYPO3_CONF_VARS']['GFX']['jpg_quality']
        );
    }

    /**
     * Emit tempImageCropped signal
     *
     * @param string $tempImageName
     * @return string
     */
    protected function emitTempImageCropped($tempImageName)
    {
        $this->getSignalSlotDispatcher()->dispatch(__CLASS__, self::SIGNAL_tempImageCropped, array($tempImageName));
    }

    /**
     * Get the SignalSlot dispatcher.
     *
     * @return \TYPO3\CMS\Extbase\SignalSlot\Dispatcher
     */
    protected function getSignalSlotDispatcher()
    {
        if (!isset($this->signalSlotDispatcher)) {
            $this->signalSlotDispatcher = $this->getObjectManager()->get(\TYPO3\CMS\Extbase\SignalSlot\Dispatcher::class);
        }
        return $this->signalSlotDispatcher;
    }

    /**
     * Gets the ObjectManager.
     *
     * @return \TYPO3\CMS\Extbase\Object\ObjectManager
     */
    protected function getObjectManager()
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Extbase\Object\ObjectManager::class);
    }
}
