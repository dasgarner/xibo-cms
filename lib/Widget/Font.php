<?php
/**
 * Copyright (C) 2020 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - http://www.xibo.org.uk
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Xibo\Widget;
use Slim\Http\Response as Response;
use Slim\Http\ServerRequest as Request;
use Xibo\Exception\InvalidArgumentException;
use Xibo\Exception\NotFoundException;

/**
 * Class Font
 * @package Xibo\Widget
 */
class Font extends ModuleWidget
{
    /** @inheritdoc */
    public function edit(Request $request, Response $response): Response
    {
        // Non-editable
        return $response;
    }

    /**
     * @inheritDoc
     */
    public function installFiles()
    {
        // Create font media items for each of the fonts found in the theme default fonts folder
        $folder = PROJECT_ROOT . '/modules/fonts';
        foreach (array_diff(scandir($folder), array('..', '.')) as $file) {

            $filePath = $folder . DIRECTORY_SEPARATOR . $file;

            $font = $this->mediaFactory->create($file, $filePath, 'font', 1);
            $font->alwaysCopy = true;
            $this->preProcess($font, $filePath);

            // If it already exists, then skip it
            try {
                $font = $this->mediaFactory->getByName($font->name);

                // The font record already exists, force an update to it
                $font->fileName = $filePath;
                $font->saveFile();

            } catch (NotFoundException $e) {
                // Excellent, we don't have it
                $font->save(['validate' => false]);

                // Assign the everyone permission
                $permission = $this->permissionFactory->createForEveryone($this->userGroupFactory, 'Xibo\\Entity\\Media', $font->getId(), 1, 0, 0);
                $permission->save();
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function settingsForm()
    {
        return 'font-form-settings';
    }

    /**
     * @inheritDoc
     */
    public function settings(Request $request, Response $response): Response
    {
        if ($this->getSanitizer($request->getParams())->getCheckbox('rebuildFonts') == 1) {
            $this->container->get('\Xibo\Controller\Library')->installFonts(['invalidateCache' => true], $request);
        }

        return $response;
    }

    /**
     * @inheritdoc
     */
    public function preProcess($media, $filePath)
    {
        parent::preProcess($media, $filePath);

        try {
            // Load the file and check it allows embedding.
            $font = \FontLib\Font::load($filePath);

            if ($font == null)
                throw new InvalidArgumentException(__('Font file unreadable'), 'filePath');

            // Reset the media name to be the font file name
            $media->name = $font->getFontName() . ' ' . $font->getFontSubfamily();

            // Font type
            $embed = intval($font->getData('OS/2', 'fsType'));

            $this->getLog()->debug('Font name adjusted to %s and embeddable flag is %s', $media->name, $embed);

            if ($embed != 0 && $embed != 8)
                throw new InvalidArgumentException(__('Font file is not embeddable due to its permissions'), 'embed');

            // Free up the file
            $font->close();
        } catch (InvalidArgumentException $invalidArgumentException) {
            throw $invalidArgumentException;
        } catch (\Exception $exception) {
            $this->getLog()->debug($exception->getTraceAsString());
            $this->getLog()->error('Unknown error installing font: ' . $exception->getMessage());
            throw new InvalidArgumentException(__('Cannot install font, unknown error'), 'font');
        }
    }

    /**
     * @inheritDoc
     */
    public function preview($width, $height, $scaleOverride = 0)
    {
        // Never previewed in the browser.
        return $this->previewIcon();
    }

    /** @inheritDoc */
    public function getResource($displayId = 0)
    {
        return '';
    }

    /**
     * Is this module valid
     * @return int
     */
    public function isValid()
    {
        // Yes
        return 1;
    }
}
