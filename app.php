<?php
declare(strict_types = 1);

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use Silly\Application;
use Symfony\Component\Console\Output\OutputInterface;

require_once __DIR__ . '/vendor/autoload.php';

$dotenv = new Dotenv(__DIR__);
$dotenv->load();

$app = new Application;

// DB
$db = \Doctrine\DBAL\DriverManager::getConnection([
    'dbname' => getenv('DB_NAME'),
    'user' => getenv('DB_USER'),
    'password' => getenv('DB_PASSWORD'),
    'host' => getenv('DB_HOST'),
    'port' => getenv('DB_PORT'),
    'driver' => 'pdo_mysql',
    'charset' => 'UTF8',
], new \Doctrine\DBAL\Configuration());

$app->command('sync repository [--since-forever]', function (string $repository, bool $sinceForever = null, OutputInterface $output) use ($db) {
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

    $response = $http->request('GET', "https://api.github.com/repos/$repository/issues", [
        'headers' => [
            'Authorization' => 'token ' . getenv('GITHUB_TOKEN'),
        ],
        'query' => [
            'state' => 'all',
            'since' => $since,
            'per_page' => 100,
        ]
    ]);
    $issues = json_decode((string) $response->getBody(), true);

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

$app->run();
