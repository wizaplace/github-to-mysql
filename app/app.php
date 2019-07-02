<?php

use Dotenv\Dotenv;
use Silly\Application;
use Symfony\Component\Console\Output\OutputInterface;
use GitHubToMysql\Github;
use GitHubToMysql\Database;

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env file if it exists
$cwd = getcwd();
if ($cwd !== false && is_file($cwd . '/.env')) {
    $dotenv = Dotenv::create($cwd);
    $dotenv->load();
}

$app = new Application;
$app->setDefaultCommand('intro');

$app->command('intro', function (OutputInterface $output) {
    $output->writeln('<comment>Getting started</comment>');
    $output->writeln('- copy the <info>.env.dist</info> file to <info>.env</info> and set up the required configuration parameters.');
    $output->writeln('- run <info>db-init</info> to setup the database (by default no SQL command will actually be run so that you can check them).');
    $output->writeln('- run <info>sync [user/repository]</info> to synchronize GitHub data with the database.');
    $output->writeln('The first time you use this command you will want to run <info>sync [user/repository] --since-forever</info> to synchronize everything.');
    $output->writeln('Then you can (for example) setup a cron to run every hour without the <info>--since-forever</info> flag.');
})->descriptions('Displays an introduction on how to use this application.');

$app->command('sync repository [--since-forever]', function ($repository, $sinceForever = null, OutputInterface $output) {
    // Labels
    Github::fetchResultsForAllPages(
        'GET',
        "https://api.github.com/repos/$repository/labels",
        [
            'Authorization' => 'token ' . getenv('GITHUB_TOKEN'),
            'Accept' => 'application/vnd.github.symmetra-preview+json',
        ], [], function (array $labels) use ($output) {
            Github::createLabelsFromJson($labels, function (array $label) use ($output) {
                $output->writeln(sprintf('Updated label <info>%s</info>', $label['name']));
            });
        }
    );

    // Milestones
    Github::fetchResultsForAllPages(
        'GET',
        "https://api.github.com/repos/$repository/milestones",
        [
            'Authorization' => 'token ' . getenv('GITHUB_TOKEN'),
        ], [], function (array $milestones) use ($output) {
            Github::createMilestonesFromJson($milestones, function (array $milestone) use ($output) {
                $output->writeln(sprintf('Updated milestone <info>%s</info>', $milestone['title']));
            });
        }
    );

    // Issues
    Github::fetchResultsForAllPages(
        'GET',
        "https://api.github.com/repos/$repository/issues",
        [
            'Authorization' => 'token ' . getenv('GITHUB_TOKEN'),
        ],
        [
            'since' => (!$sinceForever) ? date('c', strtotime('-3 hours')) : null,
        ],
        function (array $issues) use ($output) {
            $output->writeln(sprintf('<info>%d</info> issues to process', count($issues)));
            Github::createIssues($issues, function (bool $isCreation, array $issue) use ($output) {
                $output->writeln(sprintf(($isCreation ? 'Created' : 'Updated') . ' issue #%d <info>%s</info>', $issue['number'], $issue['title']));
            });
        }
    );

});

$app->command('db-init [--force]', function ($force, OutputInterface $output) {
    Database::createSchema($force, function (string $query) use ($output) {
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
