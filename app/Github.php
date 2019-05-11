<?php

namespace GitHubToMysql;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class Github {

    public static function createLabelsFromJson(array $labels, \Closure $onLabelCreation): void {
        foreach ($labels as $label) {
            $data = [
                'id' => $label['id'],
                'url' => $label['url'],
                'name' => $label['name'],
                'color' => $label['color'],
            ];
            try {
                Database::connect()->insert('github_labels', $data);
            } catch (UniqueConstraintViolationException $e) {
                Database::connect()->update('github_labels', $data, [
                    'id' => $label['id'],
                ]);
            }
            $onLabelCreation->call(Database::connect(), $label);
        }
    }

    public static function createMilestonesFromJson(array $milestones, \Closure $onMilestoneCreation): void {
        foreach ($milestones as $milestone) {
            $data = [
                'id' => $milestone['number'],
                'title' => $milestone['title'],
                'description' => $milestone['description'],
                'open' => ($milestone['state'] === 'open') ? 1 : 0,
                'url' => $milestone['url'],
            ];
            try {
                Database::connect()->insert('github_milestones', $data);
            } catch (UniqueConstraintViolationException $e) {
                Database::connect()->update('github_milestones', $data, [
                    'id' => $milestone['number'],
                ]);
            }
            $onMilestoneCreation->call(Database::connect(), $milestone);
        }
    }

    public static function createIssues(array $issues, \Closure $onIssueCreation): void {
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
                Database::connect()->insert('github_issues', $data);
            } catch (UniqueConstraintViolationException $e) {
                $isCreation = false;
                Database::connect()->update('github_issues', $data, [
                    'id' => $issue['number'],
                ]);
            }

            // Remove all label links
            Database::connect()->delete('github_issue_labels', [
                'issue_id' => $issue['number'],
            ]);

            // Re-insert them
            foreach ($issue['labels'] as $label) {
                Database::connect()->insert('github_issue_labels', [
                    'issue_id' => $issue['number'],
                    'label_id' => $label['id'],
                ]);
            }

            $onIssueCreation->call(Database::connect(), $isCreation, $issue);
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