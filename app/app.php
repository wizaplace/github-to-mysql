<?php

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Silly\Application;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env file if it exists
if (is_file(getcwd() . '/.env')) {
    $dotenv = new Dotenv(getcwd());
    $dotenv->load();
}

$app = new Application;
$app->setDefaultCommand('intro');

// DB
$dbConfig = new \Doctrine\DBAL\Configuration();
$dbConfig->setFilterSchemaAssetsExpression('/^github_/');
$db = \Doctrine\DBAL\DriverManager::getConnection([
    'dbname' => getenv('DB_NAME'),
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => getenv('DB_PORT') ?: 3306,
    'driver' => 'pdo_mysql',
    'charset' => 'UTF8',
], $dbConfig);

$app->command('intro', function (OutputInterface $output) {
    $output->writeln('<comment>Getting started</comment>');
    $output->writeln('- copy the <info>.env.dist</info> file to <info>.env</info> and set up the required configuration parameters.');
    $output->writeln('- run <info>db-init</info> to setup the database (by default no SQL command will actually be run so that you can check them).');
    $output->writeln('- run <info>sync [user/repository]</info> to synchronize GitHub data with the database.');
    $output->writeln('The first time you use this command you will want to run <info>sync [user/repository] --since-forever</info> to synchronize everything.');
    $output->writeln('Then you can (for example) setup a cron to run every hour without the <info>--since-forever</info> flag.');
})->descriptions('Displays an introduction on how to use this application.');

$app->command('sync repository [--since-forever]', function ($repository, $sinceForever = null, OutputInterface $output) use ($db) {
    $http = new Client();

    // Labels
    $response = $http->request('GET', "https://api.github.com/repos/$repository/labels", [
        'headers' => [
            'Authorization' => 'token ' . getenv('GITHUB_TOKEN'),
        ],
        'query' => [
            'per_page' => 100,
        ],
    ]);
    $labels = json_decode((string) $response->getBody(), true);
    foreach ($labels as $label) {
        $sql = <<<MYSQL
INSERT INTO github_labels (id, url, name, color) VALUES (:id, :url, :name, :color)
    ON DUPLICATE KEY UPDATE id=:id, url=:url, name=:name, color=:color
MYSQL;
        $db->executeQuery($sql, [
            'id' => $label['id'],
            'url' => $label['url'],
            'name' => $label['name'],
            'color' => $label['color'],
        ]);
        $output->writeln(sprintf('Updated label <info>%s</info>', $label['name']));
    }

    // Milestones
    $response = $http->request('GET', "https://api.github.com/repos/$repository/milestones", [
        'headers' => [
            'Authorization' => 'token ' . getenv('GITHUB_TOKEN'),
        ],
        'query' => [
            'per_page' => 100,
            'state' => 'all',
        ],
    ]);
    $milestones = json_decode((string) $response->getBody(), true);
    foreach ($milestones as $milestone) {
        $sql = <<<MYSQL
INSERT INTO github_milestones (id, title, description, open, url) VALUES (:id, :title, :description, :open, :url)
    ON DUPLICATE KEY UPDATE id=:id, title=:title, description=:description, open=:open, url=:url
MYSQL;
        $db->executeQuery($sql, [
            'id' => $milestone['number'],
            'title' => $milestone['title'],
            'description' => $milestone['description'],
            'open' => ($milestone['state'] === 'open') ? 1 : 0,
            'url' => $milestone['url'],
        ]);
        $output->writeln(sprintf('Updated milestone <info>%s</info>', $milestone['title']));
    }

    $since = null;
    if (!$sinceForever) {
        // Issues updated in the last hours
        $since = date('c', strtotime('-3 hours'));
    }
    $page = 0;
    $issues = [];
    // Loop on all pages available
    while (true) {
        try {
            $response = $http->request('GET', "https://api.github.com/repos/$repository/issues", [
                'headers' => [
                    'Authorization' => 'token ' . getenv('GITHUB_TOKEN'),
                ],
                'query' => [
                    'state' => 'all',
                    'since' => $since,
                    'per_page' => 100,
                    'page' => $page,
                ],
            ]);
        } catch (ClientException $e) {
            if (!empty($issues) && $e->getResponse()->getStatusCode() === 404) {
                // Stop the loop if 404
                break;
            }
            throw $e;
        }
        $newIssues = json_decode((string) $response->getBody(), true);
        if (empty($newIssues)) {
            break;
        }
        $issues = array_merge($issues, $newIssues);
        $page++;
    }
    $output->writeln(sprintf('<info>%d</info> issues to process', count($issues)));

    foreach ($issues as $issue) {
        $sql = <<<MYSQL
INSERT INTO github_issues (id, title, open, author, author_avatar_url, created_at, updated_at, closed_at, is_pull_request, milestone_id)
    VALUES (:id, :title, :open, :author, :author_avatar_url, :created_at, :updated_at, :closed_at, :is_pull_request, :milestone_id)
    ON DUPLICATE KEY UPDATE
        id=:id,
        title=:title,
        open=:open,
        author=:author,
        author_avatar_url=:author_avatar_url,
        created_at=:created_at,
        updated_at=:updated_at,
        closed_at=:closed_at,
        is_pull_request=:is_pull_request,
        milestone_id=:milestone_id
MYSQL;
        $db->executeQuery($sql, [
            'id' => $issue['number'],
            'title' => $issue['title'],
            'open' => ($issue['state'] === 'open') ? 1 : 0,
            'author' => $issue['user']['login'],
            'author_avatar_url' => $issue['user']['avatar_url'],
            'created_at' => $issue['created_at'],
            'updated_at' => $issue['updated_at'],
            'closed_at' => $issue['closed_at'],
            'is_pull_request' => isset($issue['pull_request']),
            'milestone_id' => $issue['milestone']['number'],
        ]);
        $output->writeln(sprintf('Updated issue #%d <info>%s</info>', $issue['number'], $issue['title']));

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
    }
});

$app->command('db-init [--force]', function ($force, OutputInterface $output) use ($db) {
    $targetSchema = require __DIR__ . '/db-schema.php';
    $currentSchema = $db->getSchemaManager()->createSchema();

    $migrationQueries = $currentSchema->getMigrateToSql($targetSchema, $db->getDatabasePlatform());

    $db->transactional(function () use ($migrationQueries, $force, $output, $db) {
        foreach ($migrationQueries as $query) {
            $output->writeln(sprintf('Running <info>%s</info>', $query));
            if ($force) {
                $db->exec($query);
            }
        }
        if (empty($migrationQueries)) {
            $output->writeln('<info>The database is up to date</info>');
        }
    });

    if (!$force) {
        $output->writeln('<comment>No query was run, use the --force option to run the queries</comment>');
    } else {
        $output->writeln('<comment>Queries were successfully run against the database</comment>');
    }
});

$app->run();
