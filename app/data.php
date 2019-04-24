<?php

namespace GitHubToMysql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class data {

    public static function createSchema(Connection $db, bool $force, \Closure $onRunning = null, \Closure $onUpToDate = null): void {
        $targetSchema = require __DIR__ . '/db-schema.php';
        $currentSchema = $db->getSchemaManager()->createSchema();

        $migrationQueries = $currentSchema->getMigrateToSql($targetSchema, $db->getDatabasePlatform());

        $db->transactional(function () use ($migrationQueries, $force, $db, $onUpToDate, $onRunning) {
            foreach ($migrationQueries as $query) {
                if ($onRunning !== null) {
                    $onRunning->call($db, $query);
                }

                if ($force) {
                    $db->exec($query);
                }
            }
            if (empty($migrationQueries) && $onUpToDate !== null) {
                $onUpToDate->call($db);
            }
        });
    }

    public static function createLabelsFromJson(Connection $db, array $labels, \Closure $onLabelCreation): void {
        foreach ($labels as $label) {
            $data = [
                'id' => $label['id'],
                'url' => $label['url'],
                'name' => $label['name'],
                'color' => $label['color'],
            ];
            try {
                $db->insert('github_labels', $data);
            } catch (UniqueConstraintViolationException $e) {
                $db->update('github_labels', $data, [
                    'id' => $label['id'],
                ]);
            }
            $onLabelCreation->call($db, $label);
        }
    }

    public static function createMilestonesFromJson(Connection $db, array $milestones, \Closure $onMilestoneCreation): void {
        foreach ($milestones as $milestone) {
            $data = [
                'id' => $milestone['number'],
                'title' => $milestone['title'],
                'description' => $milestone['description'],
                'open' => ($milestone['state'] === 'open') ? 1 : 0,
                'url' => $milestone['url'],
            ];
            try {
                $db->insert('github_milestones', $data);
            } catch (UniqueConstraintViolationException $e) {
                $db->update('github_milestones', $data, [
                    'id' => $milestone['number'],
                ]);
            }
            $onMilestoneCreation->call($db, $milestone);
        }
    }

    public static function createIssues(Connection $db, array $issues, \Closure $onIssueCreation): void {
        foreach ($issues as $issue) {
            $data = [
                'id' => $issue['number'],
                'title' => $issue['title'],
                'open' => ($issue['state'] === 'open') ? 1 : 0,
                'author' => $issue['user']['login'],
                'author_avatar_url' => $issue['user']['avatar_url'],
                'created_at' => date('Y-m-d H:i:s', strtotime($issue['created_at'])),
                'updated_at' => date('Y-m-d H:i:s', strtotime($issue['updated_at'])),
                'closed_at' => $issue['closed_at'] ? date('Y-m-d H:i:s', strtotime($issue['closed_at'])) : null,
                'is_pull_request' => isset($issue['pull_request']) ? 1 : 0,
                'milestone_id' => $issue['milestone']['number'],
            ];

            $isCreation = true;
            try {
                $db->insert('github_issues', $data);
            } catch (UniqueConstraintViolationException $e) {
                $isCreation = false;
                $db->update('github_issues', $data, [
                    'id' => $issue['number'],
                ]);
            }

            // Remove all label links
            $db->delete('github_issue_labels', [
                'issue_id' => $issue['number'],
            ]);

            // Re-insert them
            foreach ($issue['labels'] as $label) {
                $db->insert('github_issue_labels', [
                    'issue_id' => $issue['number'],
                    'label_id' => $label['id'],
                ]);
            }

            $onIssueCreation->call($db, $isCreation, $issue);
        }
    }

    public static function fetchResultsForAllPages(
        string $method, string $url, array $headers, array $query, \Closure $onPageResults): void {
        $http = new Client();
        $page = 1;
        // Loop on all pages available
        while (true) {
            try {
                $response = $http->request($method, $url, [
                    'headers' => $headers,
                    'query' => array_merge($query, [
                        'state' => 'all',
                        'per_page' => 100,
                        'page' => $page,
                    ]),
                ]);
            } catch (ClientException $e) {
                if (!empty($issues) && $e->getResponse() !== null && $e->getResponse()->getStatusCode() === 404) {
                    // Stop the loop if 404
                    break;
                }
                throw $e;
            }
            $page++;
            $newResults = json_decode((string) $response->getBody(), true);
            if (empty($newResults)) {
                break;
            }
            $onPageResults->call($http, $newResults);
        }
    }
}