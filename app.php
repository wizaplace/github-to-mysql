<?php

use Doctrine\DBAL\Schema\Schema;
use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Silly\Application;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = new Dotenv(__DIR__);
$dotenv->load();

$app = new Application;

// DB
$db = \Doctrine\DBAL\DriverManager::getConnection([
    'dbname' => getenv('DB_NAME'),
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
    'host' => getenv('DB_HOST') ?: 'localhost',
    'port' => getenv('DB_PORT') ?: 3306,
    'driver' => 'pdo_mysql',
    'charset' => 'UTF8',
], new \Doctrine\DBAL\Configuration());

$app->command('sync repository [--since-forever]', function ($repository, $sinceForever = null, OutputInterface $output) use ($db) {
    $http = new Client();

    $since = null;
    if (!$sinceForever) {
        // Issues updated in the last hours
        $since = date('c', strtotime('-3 hours'));
    }

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
INSERT INTO github_issues (id, title, open, author, author_avatar_url, created_at, updated_at, closed_at, is_pull_request)
    VALUES (:id, :title, :open, :author, :author_avatar_url, :created_at, :updated_at, :closed_at, :is_pull_request)
    ON DUPLICATE KEY UPDATE
        id=:id,
        title=:title,
        open=:open,
        author=:author,
        author_avatar_url=:author_avatar_url,
        created_at=:created_at,
        updated_at=:updated_at,
        closed_at=:closed_at,
        is_pull_request=:is_pull_request
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
        ]);
        $output->writeln(sprintf('Updated issue #%d <info>%s</info>', $issue['number'], $issue['title']));

        // Remove all labels
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
    $schema = new Schema();

    // Labels
    $labelsTable = $schema->createTable('github_labels');
    $labelsTable->addColumn('id', 'integer', ['unsigned' => true]);
    $labelsTable->addColumn('url', 'string');
    $labelsTable->addColumn('name', 'string');
    $labelsTable->addColumn('color', 'string');
    $labelsTable->setPrimaryKey(['id']);
    $labelsTable->addIndex(['name']);
    // Issues
    $issuesTable = $schema->createTable('github_issues');
    $issuesTable->addColumn('id', 'integer', ['unsigned' => true]);
    $issuesTable->addColumn('title', 'text');
    $issuesTable->addColumn('open', 'boolean');
    $issuesTable->addColumn('author', 'string');
    $issuesTable->addColumn('author_avatar_url', 'string', ['notnull' => false]);
    $issuesTable->addColumn('created_at', 'datetime');
    $issuesTable->addColumn('updated_at', 'datetime');
    $issuesTable->addColumn('closed_at', 'datetime', ['notnull' => false]);
    $issuesTable->addColumn('is_pull_request', 'boolean');
    $issuesTable->setPrimaryKey(['id']);
    $issuesTable->addIndex(['author']);
    $issuesTable->addIndex(['open']);
    $issuesTable->addIndex(['created_at']);
    $issuesTable->addIndex(['updated_at']);
    $issuesTable->addIndex(['closed_at']);
    $issuesTable->addIndex(['is_pull_request']);
    // Labels
    $issueLabelsTable = $schema->createTable('github_issue_labels');
    $issueLabelsTable->addColumn('issue_id', 'integer', ['unsigned' => true]);
    $issueLabelsTable->addColumn('label_id', 'integer', ['unsigned' => true]);
    $issueLabelsTable->setPrimaryKey(['issue_id', 'label_id']);
    $issueLabelsTable->addForeignKeyConstraint('github_issues', ['issue_id'], ['id'], [
        'onUpdate' => 'CASCADE',
        'onDelete' => 'CASCADE',
    ]);

    $currentSchema = $db->getSchemaManager()->createSchema();

    $migrationQueries = $currentSchema->getMigrateToSql($schema, $db->getDatabasePlatform());

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
