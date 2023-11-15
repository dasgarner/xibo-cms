<?php
/*
 * Copyright (C) 2023 Xibo Signage Ltd
 *
 * Xibo - Digital Signage - https://xibosignage.com
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

use Carbon\Carbon;
use GuzzleHttp\Exception\RequestException;
use Xibo\Support\Exception\ConfigurationException;
use Xibo\Widget\DataType\Event;
use Xibo\Widget\Provider\DataProviderInterface;
use Xibo\Widget\Provider\DurationProviderNumItemsTrait;
use Xibo\Widget\Provider\WidgetProviderInterface;
use Xibo\Widget\Provider\WidgetProviderTrait;

/**
 * Download and parse an ISC feed
 */
class MuslimPrayerTimesProvider implements WidgetProviderInterface
{
    use WidgetProviderTrait;
    use DurationProviderNumItemsTrait;

    /**
     * Fetch the ISC feed and load its data.
     * @inheritDoc
     */
    public function fetchData(DataProviderInterface $dataProvider): WidgetProviderInterface
    {
        // Latitude/Longitude determines prayer times
        if ($dataProvider->getProperty('useDisplayLocation') == 0) {
            $providedLat = $dataProvider->getProperty('latitude', $dataProvider->getDisplayLatitude());
            $providedLon = $dataProvider->getProperty('longitude', $dataProvider->getDisplayLongitude());
        } else {
            $providedLat = $dataProvider->getDisplayLatitude();
            $providedLon = $dataProvider->getDisplayLongitude();
        }

        $cache = $dataProvider->getPool()->getItem('/widget/'
            . $dataProvider->getDataType() . '/' . $providedLat . ',' . $providedLon);

        $data = $cache->get();

        if ($cache->isMiss() || $data === null) {
            // Make a new request.
            $this->getLog()->debug('fetchData: cache miss');

            try {
                // Create a Guzzle Client to get the Feed XML
                $response = $dataProvider
                    ->getGuzzleClient([
                        'timeout' => 20, // wait no more than 20 seconds
                    ])
                    ->get('https://api.aladhan.com/v1/calendar/2023/11?latitude='
                        . $providedLat . '&longitude=' . $providedLon);

                $data = $response->getBody()->getContents();

                // Save the resonse to cache
                $cache->set($data);
                $cache->expiresAt(Carbon::now()->startOfMonth()->addMonth());
                $dataProvider->getPool()->saveDeferred($cache);
            } catch (RequestException $requestException) {
                // Log and return empty?
                $this->getLog()->error('fetchData: Unable to get feed: ' . $requestException->getMessage());
                $this->getLog()->debug($requestException->getTraceAsString());

                throw new ConfigurationException(__('Unable to download feed'));
            }
        } else {
            $this->getLog()->debug('fetchData: cache hit');
        }

        try {
            $times = json_decode($data, true);

            // Determine our window
            switch ($dataProvider->getProperty('startIntervalFrom', 'day')) {
                case 'day':
                    $from = Carbon::now()->startOfDay();
                    $to = Carbon::now()->endOfDay();
                    break;
                case 'week':
                    $from = Carbon::now()->startOfWeek();
                    $to = Carbon::now()->endOfWeek();
                    break;
                default:
                    $from = Carbon::now()->startOfMonth();
                    $to = Carbon::now()->endOfMonth();
            }

            // Go through each event returned
            foreach ($times['data'] as $event) {
                try {
                    // What is the date?
                    $date = Carbon::createFromTimestamp($event['date']['timestamp']);
                    if ($date->isBefore($from) || $date->isAfter($to)) {
                        continue;
                    }

                    foreach ($event['timings'] as $prayer => $time) {
                        $entry = new Event();
                        $entry->summary = $prayer;
                        $entry->description = $prayer;
                        $entry->location = '';
                        $entry->startDate = $date->copy()->setTimeFromTimeString($time);
                        $entry->endDate = $entry->startDate->copy()->addMinutes(15);
                        $dataProvider->addItem($entry);
                    }
                } catch (\Exception $exception) {
                    $this->getLog()->error('Unable to parse event. ' . var_export($event, true));
                }
            }

            $dataProvider->setCacheTtl($to->diffInSeconds(Carbon::now()));
            $dataProvider->setIsHandled();
        } catch (\Exception $exception) {
            $this->getLog()->error('fetchData: ' . $exception->getMessage());
            $this->getLog()->debug($exception->getTraceAsString());
        }
        return $this;
    }

    public function getDataCacheKey(DataProviderInterface $dataProvider): ?string
    {
        // No special cache key requirements.
        return null;
    }

    public function getDataModifiedDt(DataProviderInterface $dataProvider): ?Carbon
    {
        return null;
    }
}
