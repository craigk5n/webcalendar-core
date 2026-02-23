<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use Icalendar\Component\VCalendar;
use Icalendar\Component\VFreeBusy;
use Icalendar\Writer\Writer;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Service for generating calendar feeds (RSS, Free/Busy).
 */
final readonly class FeedService
{
    private LoggerInterface $logger;

    public function __construct(
        private EventService $eventService,
        private string $baseUrl = 'https://example.com/calendar',
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Generates an RFC 5545 VFREEBUSY feed.
     */
    public function generateFreeBusy(User $user, DateRange $range): string
    {
        $this->logger->debug('Generating FreeBusy feed', ['user' => $user->login()]);

        $vcalendar = new VCalendar();
        $vcalendar->setProductId('-//WebCalendar//NONSGML v4.0//EN');
        $vcalendar->setVersion('2.0');

        $vfb = new VFreeBusy();
        $vfb->setUid(bin2hex(random_bytes(16)));
        $vfb->setDtStamp((new \DateTimeImmutable())->format('Ymd\THis\Z'));
        $vfb->setDtStart($range->startDate()->format('Ymd\THis\Z'));
        $vfb->setDtEnd($range->endDate()->format('Ymd\THis\Z'));

        $events = $this->eventService->getEventsInDateRange($range, $user);

        foreach ($events as $event) {
            $period = sprintf(
                '%s/%s',
                $event->start()->format('Ymd\THis\Z'),
                $event->end()->format('Ymd\THis\Z')
            );
            $vfb->addFreeBusy($period, VFreeBusy::FBTYPE_BUSY);
        }

        $vcalendar->addComponent($vfb);

        $writer = new Writer();
        return $writer->write($vcalendar);
    }

    /**
     * Generates an RSS 2.0 feed of upcoming events.
     */
    public function generateRss(User $user, DateRange $range): string
    {
        $this->logger->debug('Generating RSS feed', ['user' => $user->login()]);

        $events = $this->eventService->getEventsInDateRange($range, $user);
        
        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8" ?><rss version="2.0"></rss>');
        $channel = $xml->addChild('channel');
        $this->addTextChild($channel, 'title', 'Upcoming Events for ' . $user->fullName());
        $this->addTextChild($channel, 'link', $this->baseUrl);
        $this->addTextChild($channel, 'description', 'Calendar events feed');

        foreach ($events as $event) {
            $item = $channel->addChild('item');
            $this->addTextChild($item, 'title', $event->name());
            $this->addTextChild($item, 'description', $event->description());
            $item->addChild('pubDate', $event->start()->format(\DateTimeInterface::RSS));
            $this->addTextChild($item, 'guid', $event->uid());
        }

        $dom = dom_import_simplexml($xml)->ownerDocument;
        if ($dom === null) {
            return '';
        }
        $dom->formatOutput = true;
        return (string)$dom->saveXML();
    }

    /**
     * Adds a text child with proper XML escaping to prevent XSS.
     * 
     * Uses CDATA sections to safely include user-generated content.
     */
    private function addTextChild(\SimpleXMLElement $parent, string $name, string $value): void
    {
        $child = $parent->addChild($name);
        if ($child === null) {
            return;
        }
        
        $domChild = dom_import_simplexml($child);
        $ownerDocument = $domChild->ownerDocument;
        if ($ownerDocument === null) {
            return;
        }
        
        $cdata = $ownerDocument->createCDATASection($value);
        if ($cdata === false) {
            return;
        }
        
        $domChild->appendChild($cdata);
    }
}
