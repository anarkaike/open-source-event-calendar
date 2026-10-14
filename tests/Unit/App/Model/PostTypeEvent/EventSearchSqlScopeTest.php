<?php

namespace Osec\Tests\Unit\App\Model\PostTypeEvent;

use Osec\App\Model\Date\DT;
use Osec\App\Model\PostTypeEvent\Event;
use Osec\App\Model\PostTypeEvent\EventSearch;
use Osec\Tests\Utilities\TestBase;

/**
 * Optional SQL scopes must apply before event pagination and to its navigation checks.
 *
 * @group agenda
 */
class EventSearchSqlScopeTest extends TestBase
{
    public function test_scope_filters_between_and_relative_searches_before_pagination()
    {
        global $osec_app;

        $this->create_event('Hidden before', '2030-06-14 12:00:00');
        $visible_id = $this->create_event('Visible event', '2030-06-15 12:00:00');
        $this->create_event('Hidden after', '2030-06-16 12:00:00');
        update_post_meta($visible_id, 'osec_scope_fixture', 'visible');

        $scope = static function (array $default, string $query_type, array $context) use ($visible_id): array {
            global $wpdb;

            if (
                ('relative' === $query_type && 1 !== ($context['limit'] ?? null))
                || ('between' === $query_type && empty($context['start']))
            ) {
                return $default;
            }

            return [
                'join'       => ' INNER JOIN ' . $wpdb->postmeta . ' AS osec_scope_fixture ON '
                    . 'osec_scope_fixture.post_id = p.ID AND osec_scope_fixture.meta_key = %s '
                    . 'AND osec_scope_fixture.meta_value = %s ',
                'join_args'  => ['osec_scope_fixture', 'visible'],
                'where'      => ' AND p.ID = %d ',
                'where_args' => [$visible_id],
            ];
        };
        add_filter('osec_event_search_sql_scope', $scope, 10, 3);

        try {
            $between = EventSearch::factory($osec_app)->get_events_between(
                new DT('2030-06-01 00:00:00', 'UTC'),
                new DT('2030-06-30 23:59:59', 'UTC')
            );
            $this->assertSame(
                [$visible_id],
                array_map(static fn(Event $event): int => (int) $event->get('post_id'), $between)
            );

            $relative = EventSearch::factory($osec_app)->get_events_relative_to(
                strtotime('2030-06-15 11:59:00 UTC'),
                1,
                0
            );
            $this->assertSame(
                [$visible_id],
                array_map(static fn(Event $event): int => (int) $event->get('post_id'), $relative['events'])
            );
            $this->assertFalse($relative['prev']);
            $this->assertFalse($relative['next']);

            $relative_with_last_day = EventSearch::factory($osec_app)->get_events_relative_to(
                strtotime('2030-06-15 11:59:00 UTC'),
                1,
                0,
                [],
                strtotime('2030-06-15 11:59:00 UTC')
            );
            $this->assertSame(
                [$visible_id],
                array_map(
                    static fn(Event $event): int => (int) $event->get('post_id'),
                    $relative_with_last_day['events']
                )
            );
        } finally {
            remove_filter('osec_event_search_sql_scope', $scope, 10);
        }
    }

    public function test_invalid_scope_fails_closed()
    {
        global $osec_app;

        $this->create_event('Must be hidden by invalid scope', '2030-06-15 12:00:00');
        $scope = static fn(): array => [
            'join'        => '',
            'join_args'   => [],
            'where'       => '',
            'where_args' => 'not-a-list',
        ];
        add_filter('osec_event_search_sql_scope', $scope, 10, 3);

        try {
            $events = EventSearch::factory($osec_app)->get_events_between(
                new DT('2030-06-01 00:00:00', 'UTC'),
                new DT('2030-06-30 23:59:59', 'UTC')
            );
            $this->assertSame([], $events);
        } finally {
            remove_filter('osec_event_search_sql_scope', $scope, 10);
        }
    }

    public function test_scope_where_clause_without_and_fails_closed()
    {
        global $osec_app;

        $this->create_event('Must be hidden by invalid where clause', '2030-06-15 12:00:00');
        $scope = static fn(): array => [
            'join'       => '',
            'join_args'  => [],
            'where'      => 'p.ID = 999999',
            'where_args' => [],
        ];
        add_filter('osec_event_search_sql_scope', $scope, 10, 3);

        try {
            $events = EventSearch::factory($osec_app)->get_events_between(
                new DT('2030-06-01 00:00:00', 'UTC'),
                new DT('2030-06-30 23:59:59', 'UTC')
            );
            $this->assertSame([], $events);
        } finally {
            remove_filter('osec_event_search_sql_scope', $scope, 10);
        }
    }

    public function test_scope_placeholder_mismatch_fails_closed()
    {
        global $osec_app, $wpdb;

        $this->create_event('Must be hidden by mismatched placeholder', '2030-06-15 12:00:00');
        $scope = static fn(): array => [
            'join'       => '',
            'join_args'  => [],
            'where'      => ' AND p.ID = %d ',
            'where_args' => [],
        ];
        add_filter('osec_event_search_sql_scope', $scope, 10, 3);

        try {
            $events = EventSearch::factory($osec_app)->get_events_between(
                new DT('2030-06-01 00:00:00', 'UTC'),
                new DT('2030-06-30 23:59:59', 'UTC')
            );
            $this->assertSame([], $events);
            $this->assertSame('', $wpdb->last_error);
        } finally {
            remove_filter('osec_event_search_sql_scope', $scope, 10);
        }
    }

    public function test_scope_unsupported_placeholder_fails_closed()
    {
        global $osec_app, $wpdb;

        $this->create_event('Must be hidden by unsupported placeholder', '2030-06-15 12:00:00');
        $scope = static fn(): array => [
            'join'       => '',
            'join_args'  => [],
            'where'      => ' AND p.ID = %i ',
            'where_args' => [1],
        ];
        add_filter('osec_event_search_sql_scope', $scope, 10, 3);

        try {
            $events = EventSearch::factory($osec_app)->get_events_between(
                new DT('2030-06-01 00:00:00', 'UTC'),
                new DT('2030-06-30 23:59:59', 'UTC')
            );
            $this->assertSame([], $events);
            $this->assertSame('', $wpdb->last_error);
        } finally {
            remove_filter('osec_event_search_sql_scope', $scope, 10);
        }
    }

    private function create_event(string $title, string $start): int
    {
        global $osec_app;

        $post_id = self::factory()->post->create([
            'post_type'   => OSEC_POST_TYPE,
            'post_title'  => $title,
            'post_status' => 'publish',
        ]);
        $end = new DT($start, 'UTC');
        $end->adjust(1, 'hour');
        (new Event(
            $osec_app,
            [
                'post_id'          => $post_id,
                'post'             => get_post($post_id),
                'start'            => new DT($start, 'UTC'),
                'end'              => $end,
                'allday'           => 0,
                'timezone_name'    => 'UTC',
                'recurrence_rules' => '',
                'recurrence_dates' => '',
                'exception_rules'  => '',
                'exception_dates'  => '',
            ]
        ))->save(false);

        return $post_id;
    }
}
