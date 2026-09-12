<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Report;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Repository\ReportRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\AccessLevel;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventCollection;

/**
 * Service for generating custom reports based on templates.
 */
final class ReportService
{
    public function __construct(
        private readonly ReportRepositoryInterface $reportRepository,
        private readonly EventService $eventService
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
     *
     * A report covers exactly one calendar -- legacy's webcal_report.cal_user,
     * "user calendar to display (NULL indicates current user)" -- so
     * $targetLogin names whose calendar to report on and defaults to the
     * actor's own. What the actor is allowed to see of it:
     *
     * - their own calendar: every entry, whatever its access level;
     * - another user's, as an admin: every entry of that user's;
     * - another user's, otherwise: public entries only.
     *
     * That last rule is deliberately conservative. Legacy grants per-calendar
     * rights through webcal_access_user, which this library has no repository
     * for yet, so there is no way here to tell an authorized viewer from any
     * other user -- and under-reporting is the safe side of that to err on.
     * When those grants land, this is the method that should consult them.
     *
     * @param User $actor The user running the report.
     * @param string|null $targetLogin Calendar to report on; defaults to $actor.
     */
    public function generateFullReport(
        Report $report,
        DateRange $range,
        User $actor,
        ?string $targetLogin = null
    ): string {
        // Section 18.2 says Page Template: ${days}, Date Template: ${events}, ${date}, ${fulldate}

        $events = $this->findReportableEvents($range, $actor, $targetLogin ?? $actor->login());
        $output = '';

        foreach ($events as $event) {
            $output .= $this->generateEventReport($report, $event) . "\n";
        }

        return $output;
    }

    /**
     * Applies the access rules described on {@see generateFullReport()}.
     *
     * Every branch pins the query to a single calendar. Calling
     * getEventsInDateRange() with neither a user nor an access level reaches
     * the repository's unfiltered admin path, which returns every user's
     * PRIVATE and CONFIDENTIAL entries -- never right for a report.
     */
    private function findReportableEvents(
        DateRange $range,
        User $actor,
        string $targetLogin
    ): EventCollection {
        $onlyThisCalendar = [$targetLogin];

        if ($targetLogin === $actor->login()) {
            return $this->eventService->getEventsInDateRange(
                $range,
                $actor,
                null,
                $onlyThisCalendar
            );
        }

        if ($actor->isAdmin()) {
            return $this->eventService->getEventsInDateRange(
                $range,
                null,
                null,
                $onlyThisCalendar
            );
        }

        return $this->eventService->getEventsInDateRange(
            $range,
            null,
            AccessLevel::PUBLIC->value,
            $onlyThisCalendar
        );
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
