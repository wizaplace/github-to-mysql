<?php

use Dotenv\Dotenv;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Silly\Application;
use Symfony\Component\Console\Output\OutputInterface;
use GitHubToMysql\data;

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env file if it exists
$cwd = getcwd();
if ($cwd !== false && is_file($cwd . '/.env')) {
    $dotenv = Dotenv::create($cwd);
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
    'charset' => 'utf8mb4',
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
            'Accept' => 'application/vnd.github.symmetra-preview+json',
        ],
        'query' => [
            'per_page' => 100,
        ],
    ]);

    data::createLabelsFromJson($db, (string) $response->getBody(), function (array $label) use ($output) {
        $output->writeln(sprintf('Updated label <info>%s</info>', $label['name']));
    });

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

    data::createMilestonesFromJson($db, (string) $response->getBody(), function (array $milestone) use ($output) {
        $output->writeln(sprintf('Updated milestone <info>%s</info>', $milestone['title']));
    });

    $since = null;
    if (!$sinceForever) {
        // Issues updated in the last hours
        $since = date('c', strtotime('-3 hours'));
    }
    $page = 1;
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
            if (!empty($issues) && $e->getResponse() !== null && $e->getResponse()->getStatusCode() === 404) {
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

    data::createIssues($db, $issues, function (array $issue, bool $isCreation) use ($output) {
        $output->writeln(sprintf(($isCreation ? 'Created' : 'Updated') . ' issue #%d <info>%s</info>', $issue['number'], $issue['title']));
    });
});

$app->command('db-init [--force]', function ($force, OutputInterface $output) use ($db) {
    data::createSchema($db, $force, function (string $query) use ($output) {
        $output->writeln(sprintf('Running <info>%s</info>', $query));
    }, function () use ($output) {
        $output->writeln('<info>The database is up to date</info>');
    });

    if (!$force) {
        $output->writeln('<comment>No query was run, use the --force option to run the queries</comment>');
    } else {
        $output->writeln('<comment>Queries were successfully run against the database</comment>');
    }
});

$app->run();
