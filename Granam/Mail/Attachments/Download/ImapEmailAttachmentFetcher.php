<?php
declare(strict_types=1); // on PHP 7+ are standard PHP methods strict to types of given parameters

namespace Granam\GpWebPay\Flat;

use Granam\Strict\Object\StrictObject;

/** @link https://stackoverflow.com/questions/2649579/downloading-attachments-to-directory-with-imap-in-php-randomly-works */
class ImapEmailAttachmentFetcher extends StrictObject
{

    /** @var ImapReadOnlyConnection */
    private $imapReadOnlyConnection;
    /** @var string */
    private $dirToSave;

    public function __construct(ImapReadOnlyConnection $imapReadOnlyConnection, string $dirToSave)
    {
        $this->imapReadOnlyConnection = $imapReadOnlyConnection;
        $this->dirToSave = $dirToSave;
    }

    /**
     * @param ImapSearchCriteria $imapSearchCriteria
     * @return array|string[] List of file
     * @throws \RuntimeException
     */
    public function fetchAttachments(ImapSearchCriteria $imapSearchCriteria): array
    {
        $inbox = $this->imapReadOnlyConnection->getResource();
        $emailNumbers = imap_search($inbox, $imapSearchCriteria->getAsString(), SE_FREE, $imapSearchCriteria->getCharsetForSearch());
        if (count($emailNumbers) === 0) {
            $this->imapReadOnlyConnection->closeResource();

            return [];
        }
        $attachmentFiles = [];
        foreach ($emailNumbers as $messageNumber) {
            /* get mail structure */
            $structure = imap_fetchstructure($inbox, $messageNumber);
            $attachments = [];
            if (!empty($structure->parts)) {
                foreach ($structure->parts as $index => $part) {
                    $attachment = $this->collectAttachment($part, $inbox, $messageNumber, $index + 1);
                    if ($attachment) {
                        $attachments[] = $attachment;
                    }
                }
            }
            foreach ($attachments as $attachment) {
                if ($attachment['is_attachment']) {
                    $attachmentFiles[] = $this->writeAttachment($attachment['attachment']);
                }
            }
        }
        $this->imapReadOnlyConnection->closeResource();

        return $attachmentFiles;
    }

    /**
     * @param object $part
     * @param $inbox
     * @param $messageNumber
     * @param int $section
     * @return array|null
     */
    private function collectAttachment($part, $inbox, $messageNumber, int $section)
    {
        $attachment = [
            'is_attachment' => false,
            'filename' => '',
            'name' => '',
            'attachment' => ''
        ];
        if ($part->ifdparameters) { // TRUE if the dparameters array exists
            foreach ($part->dparameters as $object) {
                if (strtolower($object->attribute) === 'filename') {
                    $attachment['is_attachment'] = true;
                    $attachment['filename'] = $object->value;
                }
            }
        }
        if ($part->ifparameters) { // TRUE if the parameters array exists
            foreach ($part->parameters as $object) {
                if (strtolower($object->attribute) === 'name') {
                    $attachment['is_attachment'] = true;
                    $attachment['name'] = $object->value;
                }
            }
        }

        if ($attachment['is_attachment']) {
            $attachment['attachment'] = imap_fetchbody($inbox, $messageNumber, $section);
            if ((int)$part->encoding === ENCBASE64) {
                $attachment['attachment'] = base64_decode($attachment['attachment']);
            } elseif ((int)$part->encoding === ENCQUOTEDPRINTABLE) {
                $attachment['attachment'] = quoted_printable_decode($attachment['attachment']);
            }
        }

        if ($attachment['is_attachment']) {
            return $attachment;
        }

        return null;
    }

    private function writeAttachment($attachment): string
    {
        $this->writeAttachment($attachment['attachment']);
        if (!file_exists($this->dirToSave) && !@mkdir($this->dirToSave, 0770, true) && !is_dir($this->dirToSave)) {
            throw new \RuntimeException('Could not create dir to save email attachments: ' . $this->dirToSave);
        }
        $filename = (uniqid('imap', true) . '.attachment');
        $fullFilename = $this->dirToSave . rtrim('\\/') . '/' . $filename;
        $handle = fopen($fullFilename, 'wb');
        if (!$handle) {
            throw new \RuntimeException('Could not save an email attachment as ' . $fullFilename);
        }
        if (fwrite($handle, $attachment['attachment']) === false) {
            fclose($handle);
            unlink($fullFilename);
            throw new \RuntimeException('Could not write an email attachment into ' . $fullFilename);
        }
        fclose($handle);

        return $fullFilename;
    }

}