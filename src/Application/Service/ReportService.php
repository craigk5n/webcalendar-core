<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Report;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Repository\ReportRepositoryInterface;
use WebCalendar\Core\Domain\ValueObject\DateRange;

/**
 * Service for generating custom reports based on templates.
 */
final readonly class ReportService
{
    public function __construct(
        private ReportRepositoryInterface $reportRepository,
        private EventService $eventService
    ) {
    }

    /**
     * Generates a report fragment for a single event.
     */
    public function generateEventReport(Report $report, Event $event): string
    {
        $template = $report->template('E');
        if ($template === null) {
            return $event->name();
        }

        $variables = [
            '${name}' => $event->name(),
            '${description}' => $event->description(),
            '${date}' => $event->start()->format('Y-m-d'),
            '${time}' => $event->start()->format('H:i'),
            '${location}' => $event->location()
        ];

        return strtr($template, $variables);
    }

    /**
     * Generates a full report for a date range.
     */
    public function generateFullReport(Report $report, DateRange $range, ?string $userLogin = null): string
    {
        // For now, simpler implementation just listing events.
        // Section 18.2 says Page Template: ${days}, Date Template: ${events}, ${date}, ${fulldate}
        
        $events = $this->eventService->getEventsInDateRange($range);
        $output = '';

        foreach ($events as $event) {
            $output .= $this->generateEventReport($report, $event) . "\n";
        }

        return $output;
    }

    public function getReportById(int $id): ?Report
    {
        return $this->reportRepository->findById($id);
    }

    /**
     * @return Report[]
     */
    public function getReportsForUser(string $login): array
    {
        $global = $this->reportRepository->findAllGlobal();
        $personal = $this->reportRepository->findByOwner($login);
        
        return array_merge($global, $personal);
    }
}
