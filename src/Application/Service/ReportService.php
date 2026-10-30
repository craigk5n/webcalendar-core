<?php

declare(strict_types=1);

namespace WebCalendar\Core\Application\Service;

use WebCalendar\Core\Domain\Entity\Report;
use WebCalendar\Core\Domain\Entity\Event;
use WebCalendar\Core\Domain\Repository\ReportRepositoryInterface;
use WebCalendar\Core\Domain\Entity\User;
use WebCalendar\Core\Domain\ValueObject\DateRange;
use WebCalendar\Core\Domain\ValueObject\EventScope;
use WebCalendar\Core\Domain\ValueObject\EventCollection;

/**
 * Service for generating custom reports based on templates.
 */
final class ReportService
{
    /** @var \Closure(string, string): string */
    private readonly \Closure $valueEncoder;

    /**
     * @param (\Closure(string, string): string)|null $valueEncoder Encodes an
     *   event field before it is substituted into a template. Receives the
     *   value and the field name ('name', 'description', 'location'). The
     *   default HTML-escapes every field, because report templates are HTML
     *   and the values belong to the calendar owner, who may be a different,
     *   lower-trust user than the one running the report. Pass a different
     *   encoder for plain-text output, or to run descriptions through an
     *   allow-list HTML sanitizer when rich descriptions are enabled.
     */
    public function __construct(
        private readonly ReportRepositoryInterface $reportRepository,
        private readonly EventService $eventService,
        private readonly ?CalendarAccessService $calendarAccess = null,
        ?\Closure $valueEncoder = null
    ) {
        $this->valueEncoder = $valueEncoder ?? self::escapeHtml(...);
    }

    /**
     * Default value encoder: HTML-escapes every field the same way.
     */
    private static function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Generates a report fragment for a single event.
     */
    public function generateEventReport(Report $report, Event $event): string
    {
        $encode = $this->valueEncoder;
        $template = $report->template('E');
        if ($template === null) {
            return $encode($event->name(), 'name');
        }

        $variables = [
            '${name}' => $encode($event->name(), 'name'),
            '${description}' => $encode($event->description(), 'description'),
            '${date}' => $event->start()->format('Y-m-d'),
            '${time}' => $event->start()->format('H:i'),
            '${location}' => $encode($event->location(), 'location')
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
     * - another user's, with a CalendarAccessService wired: whatever that
     *   user's grants allow, per entry type and access level;
     * - another user's, without one: public entries only.
     *
     * That last rule is the conservative fallback for a caller that has not
     * wired up webcal_access_user grants. It under-reports for a user who
     * legitimately holds access to another calendar, which is the safe side
     * to err on when there is no way to tell one from any other user.
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
     * Every branch pins the query to a single calendar with
     * limitedToUsers(). Note that pinning is not itself an access filter:
     * the administrative branches below still read that calendar's private
     * entries, which is exactly why each one is reached only after an
     * explicit authorization check.
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
                EventScope::forUser($actor)->limitedToUsers($onlyThisCalendar)
            );
        }

        if ($actor->isAdmin()) {
            return $this->eventService->getEventsInDateRange(
                $range,
                EventScope::administrative()->limitedToUsers($onlyThisCalendar)
            );
        }

        if ($this->calendarAccess === null) {
            return $this->eventService->getEventsInDateRange(
                $range,
                EventScope::publicOnly()->limitedToUsers($onlyThisCalendar)
            );
        }

        // Grants are per entry type and access level, which no single WHERE
        // clause expresses, so the calendar is read whole and then filtered.
        // The rows never leave this method unless the grant allows them.
        $grant = $this->calendarAccess->effectiveAccess($actor, $targetLogin);

        if ($grant->view()->isNone()) {
            return new EventCollection([]);
        }

        $events = $this->eventService->getEventsInDateRange(
            $range,
            EventScope::administrative()->limitedToUsers($onlyThisCalendar)
        );

        $visible = [];
        foreach ($events as $event) {
            if ($grant->canView($event->type(), $event->access())) {
                $visible[] = $event;
            }
        }

        return new EventCollection($visible);
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
