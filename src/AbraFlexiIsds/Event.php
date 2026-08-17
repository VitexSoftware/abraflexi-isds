<?php

declare(strict_types=1);

/**
 * This file is part of the AbraFlexi-ISDS package
 *
 * https://github.com/VitexSoftware/abraflexi-isds/
 *
 * (c) Vítězslav Dvořák <https://vitexsoftware.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace AbraFlexiIsds;

use AbraFlexi\Adresar;
use AbraFlexi\Code;
use AbraFlexi\Priloha;
use Defr\CzechDataBox\Api\tIDMessInput;
use Defr\CzechDataBox\Api\tRecord;
use Defr\CzechDataBox\DataBox;
use Defr\CzechDataBox\DataBoxException;
use Defr\CzechDataBox\DataBoxSimpleApi;
use Ease\Shared;

/**
 * One AbraFlexi Event (evidence "udalost") created from an ISDS data message.
 *
 * @author Vítězslav Dvořák <info@vitexsoftware.cz>
 */
class Event extends \AbraFlexi\Udalost
{
    /**
     * Fetch received ISDS messages and upsert an AbraFlexi Event for each one
     * not synced yet.
     *
     * @return array<string, mixed> summary report
     */
    public function createEvents(DataBox $dataBox): array
    {
        $isds = $dataBox->getSimpleApi();
        $markAsRead = strtolower(Shared::cfg('ISDS_MARK_AS_READ', 'false')) === 'true';

        $report = ['created' => 0, 'skipped' => 0, 'failed' => 0, 'messages' => []];

        foreach ($isds->getListOfReceivedMessages(
            (int) Shared::cfg('ISDS_DAYS', 90),
            (int) Shared::cfg('ISDS_LIMIT', 1000),
        ) as $message) {
            $dmId = $message->getDmID();
            $extId = 'ext:isds:'.$dmId;

            if ($this->recordExists(['id' => $extId])) {
                $this->addStatusMessage(sprintf(_('Message %s already synced'), $dmId));
                ++$report['skipped'];

                continue;
            }

            $eventData = $this->messageToEventData($message, $extId);
            $this->resolveFirma($eventData, $message, $isds);

            $this->dataReset();
            $this->setData($eventData);

            if ($this->sync()) {
                $this->attachDocuments($isds, $dmId);

                if ($markAsRead) {
                    $this->markAsRead($dataBox, $dmId);
                }

                ++$report['created'];
                $this->addStatusMessage(sprintf(_('Event created for message %s'), $dmId), 'success');
            } else {
                ++$report['failed'];
                $this->addStatusMessage(sprintf(_('Failed to sync event for message %s'), $dmId), 'error');
            }

            $report['messages'][] = ['dmId' => $dmId, 'subject' => $eventData['predmet']];
        }

        return $report;
    }

    /**
     * @return array<string, mixed>
     */
    private function messageToEventData(tRecord $message, string $extId): array
    {
        $sender = $message->getDmSender();
        $eventData = [
            'id' => $extId,
            'predmet' => $message->getDmAnnotation() ?: sprintf(_('Data message from %s'), $sender),
            'popis' => sprintf(_('ISDS data message from %s (box %s)'), $sender, $message->getDbIDSender()),
            'poznam' => sprintf(_('ISDS message ID: %s'), $message->getDmID()),
            'stitky' => 'ISDS',
        ];

        $deliveryTime = $message->getDmDeliveryTime();

        if ($deliveryTime instanceof \DateTime) {
            $eventData['termin'] = $deliveryTime;
        }

        $eventType = Shared::cfg('ISDS_ABRAFLEXI_EVENT_TYPE', '');

        if ($eventType !== '') {
            $eventData['typAkt'] = Code::ensure($eventType);
        }

        return $eventData;
    }

    /**
     * Try to link the Event to a known AbraFlexi Adresar by the sender's IČO
     * (looked up via the ISDS data box directory). Falls back to storing the
     * sender name in firmaExterni when no match is found.
     *
     * @param array<string, mixed> $eventData
     */
    private function resolveFirma(array &$eventData, tRecord $message, DataBoxSimpleApi $isds): void
    {
        $eventData['firmaExterni'] = $message->getDmSender();

        if (strtolower(Shared::cfg('ISDS_RESOLVE_FIRMA', 'true')) !== 'true') {
            return;
        }

        try {
            $ico = $isds->findDataBoxById($message->getDbIDSender())->getIc();
        } catch (DataBoxException $exc) {
            $this->addStatusMessage($exc->getMessage(), 'warning');

            return;
        }

        if (empty($ico)) {
            return;
        }

        $checker = new Adresar();

        if ($checker->recordExists(['ic' => $ico])) {
            $eventData['firma'] = 'in:'.$ico;
            unset($eventData['firmaExterni']);
        }
    }

    private function attachDocuments(DataBoxSimpleApi $isds, string $dmId): void
    {
        try {
            foreach ($isds->getReceivedDataMessageAttachments($dmId) as $attachment) {
                Priloha::addAttachmentFromFile($this, $attachment->getLocation());
            }
        } catch (DataBoxException $exc) {
            $this->addStatusMessage($exc->getMessage(), 'warning');
        }
    }

    private function markAsRead(DataBox $dataBox, string $dmId): void
    {
        try {
            $dataBox->DmInfoWebService()->MarkMessageAsDownloaded(new tIDMessInput($dmId));
        } catch (DataBoxException $exc) {
            $this->addStatusMessage($exc->getMessage(), 'warning');
        }
    }
}
