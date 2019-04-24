<?php

namespace GitHubToMysql;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

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

    public static function createLabelsFromJson(Connection $db, string $json, \Closure $onLabelCreation): void {
        $labels = json_decode($json, true);
        foreach ($labels as $label) {
            try {
                $db->insert('github_labels', [
                    'id' => $label['id'],
                    'url' => $label['url'],
                    'name' => $label['name'],
                    'color' => $label['color'],
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $db->update('github_labels', [
                    'id' => $label['id'],
                    'url' => $label['url'],
                    'name' => $label['name'],
                    'color' => $label['color'],
                ], [
                    'id' => $label['id'],
                ]);
            }
            $onLabelCreation->call($db, $label);
        }
    }

    public static function createMilestonesFromJson(Connection $db, string $json, \Closure $onMilestoneCreation): void {
        $milestones = json_decode($json, true);
        foreach ($milestones as $milestone) {
            try {
                $db->insert('github_milestones', [
                    'id' => $milestone['number'],
                    'title' => $milestone['title'],
                    'description' => $milestone['description'],
                    'open' => ($milestone['state'] === 'open') ? 1 : 0,
                    'url' => $milestone['url'],
                ]);
            } catch (UniqueConstraintViolationException $e) {
                $db->update('github_milestones', [
                    'id' => $milestone['number'],
                    'title' => $milestone['title'],
                    'description' => $milestone['description'],
                    'open' => ($milestone['state'] === 'open') ? 1 : 0,
                    'url' => $milestone['url'],
                ], [
                    'id' => $milestone['number'],
                ]);
            }
            $onMilestoneCreation->call($db, $milestone);
        }
    }

}